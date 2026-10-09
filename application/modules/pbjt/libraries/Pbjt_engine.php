<?php
defined('BASEPATH') OR (defined('PBJT_CLI') OR exit('No direct script access allowed'));

/**
 * Pbjt_engine — inti mesin rekonsiliasi PBJT + ISOLASI TENANT.
 *
 * Tenant = WP (wajib pajak). Konteks tenant menentukan data yang boleh dilihat:
 *   - role 'bapenda' (super-admin) : melihat SEMUA WP.
 *   - role 'wp'                    : HANYA melihat wp_id miliknya.
 *
 * Isolasi ditegakkan di SERVER, di satu tempat (tenantWhere), bukan di UI.
 * Kelas ini plain-PHP (mysqli) agar bisa dipakai oleh controller CI3 maupun CLI.
 */
class Pbjt_engine
{
    /** @var mysqli */
    private $db;
    private $cfg;

    public function __construct($db, array $cfg = [])
    {
        $this->db = $db;
        $this->cfg = array_merge([
            'porsi_nontunai_default' => 0.60,
            'tarif_pbjt'             => null, // null = config Pemda (config/pbjt.php)
            'ambang_skor'            => 50,
            // WP yang "dijalankan" oleh instance aplikasi ini (single-tenant).
            'app_tenant'             => 'WP002',
            // SILO: DB oversight (Bapenda) & DB kontrol (registry tenant) — default dari konfigurasi.
            'oversight_db'           => null,
            'control_db'             => null,
        ], $cfg);
        if ($this->cfg['oversight_db'] === null || $this->cfg['control_db'] === null) {
            $dir = defined('APPPATH') ? APPPATH : dirname(__DIR__, 3) . '/';
            $c = require $dir . 'config/pbjt_db.php';
            if ($this->cfg['oversight_db'] === null) $this->cfg['oversight_db'] = $c['oversight_db'];
            if ($this->cfg['control_db'] === null) $this->cfg['control_db'] = $c['control_db'];
        }
    }

    private function tarif()
    {
        if ($this->cfg['tarif_pbjt'] === null) {
            require_once __DIR__ . '/Pbjt_pajak.php';
            $this->cfg['tarif_pbjt'] = Pbjt_pajak::tarif();
        }
        return (float) $this->cfg['tarif_pbjt'];
    }

    public function appTenant() { return $this->cfg['app_tenant']; }

    /** Registry tenant aktif: [ ['wp_id'=>.., 'db_name'=>..], ... ] dari DB kontrol. */
    public function tenants()
    {
        $ctrl = $this->db->real_escape_string($this->cfg['control_db']);
        return $this->rows("SELECT wp_id, db_name FROM `$ctrl`.tenants WHERE status=1 ORDER BY wp_id");
    }

    /** Sinkron omzet oversight lalu rekonsiliasi satu masa. */
    public function refreshLive($masa)
    {
        $this->syncOmzetFromTenants($masa);
        return $this->reconcile($masa);
    }

    /** Daftar masa (YYYY-MM) yang punya transaksi di SELURUH tenant, + masa pilot. */
    public function liveMasaList()
    {
        $set = ['2026-08' => 1];
        foreach ($this->tenants() as $t) {
            $db = $this->db->real_escape_string($t['db_name']);
            foreach ($this->rows("SELECT DISTINCT DATE_FORMAT(date_time,'%Y-%m') ym FROM `$db`.booked_info WHERE date_time IS NOT NULL") as $r) if ($r['ym']) $set[$r['ym']] = 1;
            foreach ($this->rows("SELECT DISTINCT DATE_FORMAT(order_date,'%Y-%m') ym FROM `$db`.customer_order WHERE order_date IS NOT NULL") as $r) if ($r['ym']) $set[$r['ym']] = 1;
        }
        return array_keys($set);
    }

    /** Dipicu hook/Bapenda: sinkron omzet lintas tenant + rekonsiliasi semua masa terdampak. */
    public function refreshLiveAll()
    {
        $total = 0;
        foreach ($this->liveMasaList() as $masa) {
            $this->syncOmzetFromTenants($masa);
            $total += $this->reconcile($masa);
        }
        return $total;
    }

    /* ---------------------------------------------------------------------
     * KONTEKS TENANT
     * ------------------------------------------------------------------- */

    /**
     * Bangun konteks tenant dari data sesi aplikasi.
     * $isAdmin: sesi 'is_admin' aplikasi (1 = admin). $userId: sesi 'id'.
     */
    public function contextFromSession($userId, $isAdmin)
    {
        // Super-admin aplikasi diperlakukan sebagai Bapenda.
        if ((int) $isAdmin === 1) {
            return ['role' => 'bapenda', 'wp_id' => null, 'user_id' => (int) $userId];
        }
        // Selain itu, cari penautan tenant (WP) untuk user ini.
        $uid = (int) $userId;
        $r = $this->db->query("SELECT role, wp_id FROM pbjt_tenant_user WHERE user_id = $uid LIMIT 1");
        if ($r && ($row = $r->fetch_assoc())) {
            return ['role' => $row['role'], 'wp_id' => $row['wp_id'], 'user_id' => $uid];
        }
        // Tidak tertaut ke tenant apa pun -> tidak boleh melihat data apa pun.
        return ['role' => 'none', 'wp_id' => null, 'user_id' => $uid];
    }

    /**
     * SATU-SATUNYA tempat aturan isolasi tenant diterapkan.
     * Mengembalikan potongan WHERE untuk kolom wp_id sesuai konteks.
     */
    private function tenantWhere(array $ctx, $col = 'wp_id')
    {
        switch ($ctx['role']) {
            case 'bapenda':
                return '1=1';                                   // lihat semua
            case 'wp':
                $wp = $this->db->real_escape_string((string) $ctx['wp_id']);
                return "$col = '$wp'";                          // hanya miliknya
            default:
                return '1=0';                                   // tidak lihat apa pun
        }
    }

    private function rows($sql)
    {
        $out = [];
        if ($r = $this->db->query($sql)) { while ($row = $r->fetch_assoc()) $out[] = $row; }
        return $out;
    }
    private function scalar($sql)
    {
        $r = $this->db->query($sql);
        return $r ? ($r->fetch_row()[0] ?? 0) : 0;
    }

    /* ---------------------------------------------------------------------
     * BACA DATA (selalu ter-scope tenant)
     * ------------------------------------------------------------------- */

    public function kpi(array $ctx, $masa)
    {
        $masa = $this->db->real_escape_string($masa);
        $w = $this->tenantWhere($ctx);
        $isBapenda = $ctx['role'] === 'bapenda';
        return [
            'total_potensi' => (int) $this->scalar("SELECT COALESCE(SUM(potensi_rp),0) FROM pbjt_temuan WHERE masa='$masa' AND $w"),
            'jml_temuan'    => (int) $this->scalar("SELECT COUNT(*) FROM pbjt_temuan WHERE masa='$masa' AND $w"),
            'total_wp'      => $isBapenda ? (int) $this->scalar("SELECT COUNT(*) FROM pbjt_wp") : 1,
            // Antrean MID tak terpetakan hanya relevan & boleh dilihat Bapenda.
            'unmapped_n'    => $isBapenda ? (int) $this->scalar("SELECT COUNT(*) FROM pbjt_mid_unmapped WHERE masa='$masa'") : 0,
            'unmapped_rp'   => $isBapenda ? (int) $this->scalar("SELECT COALESCE(SUM(nilai_settlement),0) FROM pbjt_mid_unmapped WHERE masa='$masa'") : 0,
        ];
    }

    public function temuan(array $ctx, $masa)
    {
        $masa = $this->db->real_escape_string($masa);
        $w = $this->tenantWhere($ctx);
        return $this->rows("SELECT * FROM pbjt_temuan WHERE masa='$masa' AND $w ORDER BY potensi_rp DESC");
    }

    public function perKecamatan(array $ctx, $masa)
    {
        $masa = $this->db->real_escape_string($masa);
        $w = $this->tenantWhere($ctx);
        return $this->rows("SELECT kecamatan, SUM(potensi_rp) p, COUNT(*) n FROM pbjt_temuan WHERE masa='$masa' AND $w GROUP BY kecamatan ORDER BY p DESC");
    }

    public function unmapped(array $ctx, $masa)
    {
        // Hanya Bapenda; WP tidak boleh melihat antrean global.
        if ($ctx['role'] !== 'bapenda') return [];
        $masa = $this->db->real_escape_string($masa);
        return $this->rows("SELECT * FROM pbjt_mid_unmapped WHERE masa='$masa' ORDER BY nilai_settlement DESC");
    }

    /**
     * Rekap tagihan masa: tiap WP dalam scope + pajak terutang + tagihan & status VA-nya.
     * Bapenda: semua WP; WP: hanya miliknya (via tenantWhere).
     */
    public function tagihan(array $ctx, $masa)
    {
        if (!$this->tableExists('pbjt_tagihan')) return [];
        $masa = $this->db->real_escape_string($masa);
        $first = $masa . '-01';
        $last = date('Y-m-t', strtotime($first));
        $w = $this->tenantWhere($ctx, 'w.wp_id');
        $ctrl = $this->db->real_escape_string($this->cfg['control_db']);
        $vaJoin = $this->tableExists('snap_va', $this->cfg['control_db'])
            ? "LEFT JOIN `$ctrl`.snap_va v ON v.id = t.snap_va_id"
            : "LEFT JOIN (SELECT NULL id, NULL status, NULL expired_at) v ON 1=0";
        return $this->rows("SELECT w.wp_id, w.nama, w.jenis_usaha, COALESCE(o.pajak,0) pajak_terutang,
                t.id tagihan_id, t.pajak, t.status, t.jatuh_tempo, t.va_no, t.dibayar, t.lunas_pada,
                v.status va_status, v.expired_at va_expired_at
            FROM pbjt_wp w
            LEFT JOIN (SELECT wp_id, SUM(pajak_terlapor) pajak FROM pbjt_omzet_harian
                       WHERE tanggal BETWEEN '$first' AND '$last' GROUP BY wp_id) o ON o.wp_id = w.wp_id
            LEFT JOIN pbjt_tagihan t ON t.wp_id = w.wp_id AND t.masa = '$masa'
            $vaJoin
            WHERE $w
            ORDER BY (t.status = 'lunas'), w.wp_id");
    }

    /** Pemilik tagihan (wp_id) bila tagihan berada dalam scope konteks, selain itu null. */
    public function tagihanDalamScope(array $ctx, $tagihanId)
    {
        if (!$this->tableExists('pbjt_tagihan')) return null;
        $id = (int) $tagihanId;
        $w = $this->tenantWhere($ctx);
        $r = $this->rows("SELECT wp_id FROM pbjt_tagihan WHERE id = $id AND $w");
        return $r ? $r[0]['wp_id'] : null;
    }

    private function tableExists($table, $schema = null)
    {
        $t = $this->db->real_escape_string($table);
        $s = $schema ? "'" . $this->db->real_escape_string($schema) . "'" : 'DATABASE()';
        return (int) $this->scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = $s AND table_name = '$t'") > 0;
    }

    public function masaTersedia()
    {
        $out = array_column($this->rows("SELECT DISTINCT masa FROM pbjt_temuan ORDER BY masa DESC"), 'masa');
        return $out;
    }

    /* ---------------------------------------------------------------------
     * SINKRONISASI LIVE — tarik omzet dari transaksi NYATA aplikasi.
     *
     * Aplikasi ini single-tenant, jadi seluruh transaksinya milik SATU WP
     * (WP yang "dijalankan" instance aplikasi ini). Omzet harian dihitung dari:
     *   - booked_info & customer_order: dpp + pbjt per transaksi (Pbjt_pajak; satu engine)
     *   - customer_order.totalamount (pendapatan restoran)
     * Hasilnya menggantikan baris omzet WP tersebut -> /pbjt jadi live & otomatis.
     * ------------------------------------------------------------------- */

    /**
     * SILO: tarik omzet harian dari DB TIAP tenant (booking kamar + order resto)
     * ke lapis oversight (pbjt_omzet_harian). Dijalankan pada koneksi DB oversight (hotelv3).
     */
    public function syncOmzetFromTenants($masa)
    {
        $masa = $this->db->real_escape_string($masa);
        $first = $masa . '-01';
        $last  = sprintf('%s-%02d', $masa, (int) date('t', strtotime($first)));
        $tarif = $this->tarif();

        // Buang omzet bersumber aplikasi (device_id='APP') utk masa ini — semua WP.
        $this->db->query("DELETE FROM pbjt_omzet_harian WHERE device_id='APP' AND tanggal BETWEEN '$first' AND '$last'");

        $st = $this->db->prepare("INSERT INTO pbjt_omzet_harian (wp_id, device_id, tanggal, omzet_bruto, pajak_terlapor) VALUES (?, 'APP', ?, ?, ?)");
        $totalOmzet = 0; $nBaris = 0;

        require_once __DIR__ . '/Pbjt_pajak.php';
        foreach ($this->tenants() as $t) {
            $wp = $t['wp_id'];
            $db = $this->db->real_escape_string($t['db_name']);
            // dpp/pbjt per transaksi (satu engine untuk kamar & restoran). Oversight tidak mengubah
            // nominal bill tenant; koreksi bill belum-bayar dilakukan hook di aplikasi tenant.
            Pbjt_pajak::sinkronKamar($this->db, $t['db_name'], $tarif);
            Pbjt_pajak::sinkronResto($this->db, $t['db_name'], $tarif, false);
            $harian = []; // tgl => [omzet, pajak], khusus tenant ini
            $add = function ($sql) use (&$harian) {
                if ($r = $this->db->query($sql)) {
                    while ($row = $r->fetch_assoc()) {
                        $tgl = $row['d']; if (!$tgl) continue;
                        if (!isset($harian[$tgl])) $harian[$tgl] = [0, 0];
                        $harian[$tgl][0] += (int) round((float) $row['v']);
                        $harian[$tgl][1] += (int) round((float) $row['p']);
                    }
                }
            };
            // Transaksi batal (booking status 1, order status 5) bukan omzet.
            // Omzet = dpp + pbjt (uang yang dibayar tamu, pembanding QRIS); pajak = pbjt per transaksi.
            $add("SELECT DATE(date_time) d, SUM(dpp + pbjt) v, SUM(pbjt) p FROM `$db`.booked_info
                  WHERE DATE(date_time) BETWEEN '$first' AND '$last' AND bookingstatus <> 1 GROUP BY DATE(date_time)");
            $add("SELECT order_date d, SUM(dpp + pbjt) v, SUM(pbjt) p FROM `$db`.customer_order
                  WHERE order_date BETWEEN '$first' AND '$last' AND order_status <> 5 GROUP BY order_date");

            foreach ($harian as $tgl => $x) {
                list($omzet, $pajak) = $x;
                $st->bind_param('ssii', $wp, $tgl, $omzet, $pajak);
                $st->execute();
                $totalOmzet += $omzet; $nBaris++;
            }
        }
        $st->close();
        return ['baris' => $nBaris, 'omzet' => $totalOmzet];
    }

    /* ---------------------------------------------------------------------
     * REKONSILIASI (menulis temuan) — dijalankan Bapenda / job, bukan WP.
     * ------------------------------------------------------------------- */

    public function reconcile($masa)
    {
        $masa = $this->db->real_escape_string($masa);
        $firstDay = $masa . '-01';
        $lastDay  = sprintf('%s-%02d', $masa, (int) date('t', strtotime($firstDay)));
        $now = date('Y-m-d H:i:s');
        $p = (float) $this->cfg['porsi_nontunai_default'];
        $tarif = $this->tarif();
        $ambang = (int) $this->cfg['ambang_skor'];

        $this->db->query("DELETE FROM pbjt_temuan WHERE masa='$masa'");
        $this->db->query("DELETE FROM pbjt_mid_unmapped WHERE masa='$masa'");

        // TB per WP.
        $tb = [];
        foreach ($this->rows("SELECT wp_id, SUM(omzet_bruto) omzet FROM pbjt_omzet_harian
                              WHERE tanggal BETWEEN '$firstDay' AND '$lastDay' GROUP BY wp_id") as $r) {
            $tb[$r['wp_id']] = (int) $r['omzet'];
        }
        // QRIS per WP (semua MID milik WP dijumlahkan; refund negatif ikut).
        $qris = [];
        foreach ($this->rows("SELECT pm.wp_id, SUM(s.nilai_settlement) q, COUNT(DISTINCT s.mid) n
                              FROM pbjt_settlement_harian s JOIN pbjt_peta_mid pm ON s.mid=pm.mid
                              WHERE s.tanggal_transaksi BETWEEN '$firstDay' AND '$lastDay'
                              GROUP BY pm.wp_id") as $r) {
            $qris[$r['wp_id']] = ['q' => (int) $r['q'], 'n' => (int) $r['n']];
        }
        // MID tak terpetakan.
        $unmapped = $this->rows("SELECT s.mid, s.bank, SUM(s.nilai_settlement) nilai
                                 FROM pbjt_settlement_harian s LEFT JOIN pbjt_peta_mid pm ON s.mid=pm.mid
                                 WHERE pm.mid IS NULL AND s.tanggal_transaksi BETWEEN '$firstDay' AND '$lastDay'
                                 GROUP BY s.mid, s.bank");
        $stU = $this->db->prepare("INSERT INTO pbjt_mid_unmapped (masa, mid, bank, nilai_settlement, dibuat_pada) VALUES (?,?,?,?,?)");
        foreach ($unmapped as $u) { $n = (int) $u['nilai']; $stU->bind_param('sssis', $masa, $u['mid'], $u['bank'], $n, $now); $stU->execute(); }
        $stU->close();

        // Per WP -> temuan.
        $wpList = $this->rows("SELECT wp_id, nama, jenis_usaha, kecamatan FROM pbjt_wp ORDER BY wp_id");
        $stT = $this->db->prepare("INSERT INTO pbjt_temuan
            (masa, wp_id, nama, jenis_usaha, kecamatan, omzet_terlapor, qris_teragregasi, selisih_lantai,
             omzet_estimasi, potensi_rp, skor_risiko, cakupan_sinyal, aturan_terpicu, status_kasus, dibuat_pada)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $dibuat = 0;
        foreach ($wpList as $w) {
            $wp = $w['wp_id'];
            $omzet = $tb[$wp] ?? 0;
            $q     = $qris[$wp]['q'] ?? 0;
            $nMid  = $qris[$wp]['n'] ?? 0;
            $selisih = $q - $omzet;
            $skor = 0; $est = 0; $potensi = 0; $aturan = [];
            if ($selisih > 0) {
                $aturan[] = 'uji lantai (QRIS > omzet terlapor)';
                $est = (int) round($q / $p);
                $potensi = (int) round(max(0, $est - $omzet) * $tarif);
                $skor = 60 + (int) min(40, round($selisih / max(1, $omzet) * 100));
            }
            if ($nMid > 1) $aturan[] = "multi-MID ($nMid MID)";
            $cakupan = isset($qris[$wp]) ? 'QRIS 3-bank' : 'tanpa QRIS';
            if ($skor >= $ambang) {
                $status = 'baru';
                $aturanStr = implode('; ', $aturan);
                $stT->bind_param('sssssiiiiiissss',
                    $masa, $wp, $w['nama'], $w['jenis_usaha'], $w['kecamatan'],
                    $omzet, $q, $selisih, $est, $potensi, $skor, $cakupan, $aturanStr, $status, $now);
                $stT->execute();
                $dibuat++;
            }
        }
        $stT->close();
        $this->db->query("INSERT INTO pbjt_audit_log (waktu, aktor, aksi, detail) VALUES ('$now','mesin','rekonsiliasi','masa $masa: $dibuat temuan')");
        return $dibuat;
    }
}
