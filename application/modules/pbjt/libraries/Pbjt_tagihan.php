<?php
/**
 * Tagihan PBJT per WP per masa, dibayar lewat VA BNI (SNAP BI).
 *
 * Dasar tagihan = SUM(pbjt_omzet_harian.pajak_terlapor) masa itu, yaitu pajak yang
 * dilaporkan/tercatat (self-assessment). Potensi dari temuan rekonsiliasi SENGAJA
 * tidak ditagihkan di sini: kurang bayar perlu pemeriksaan & penetapan (SKPDKB).
 *
 * Tabel pbjt_tagihan berada di DB oversight; VA-nya di registry snap_va (DB kontrol).
 * Kelas plain-PHP (mysqli) agar dipakai controller, handler callback, dan CLI.
 */
class Pbjt_tagihan
{
    /** @var mysqli DB oversight */
    private $db;
    private $cfg;

    public function __construct(mysqli $oversight, array $cfg = [])
    {
        $this->db = $oversight;
        $this->cfg = array_merge([
            // Tanggal jatuh tempo pada bulan setelah masa. SESUAIKAN dengan Perda/Perkada.
            'tanggal_jatuh_tempo' => 15,
            // Bila diterbitkan setelah jatuh tempo, VA tetap aktif selama N hari.
            'masa_aktif_va_terlambat' => 7,
        ], $cfg);
    }

    /** Batas aktif VA: akhir hari jatuh tempo, atau N hari dari sekarang bila sudah terlambat. */
    public function batasVa($masa, $now = null)
    {
        $now = $now === null ? time() : $now;
        $jt = strtotime($this->jatuhTempo($masa) . ' 23:59:59');
        return date('Y-m-d H:i:s', $jt > $now ? $jt : strtotime('+' . (int) $this->cfg['masa_aktif_va_terlambat'] . ' days 23:59:59', $now));
    }

    public static function migrate(mysqli $db)
    {
        $ok = $db->query("CREATE TABLE IF NOT EXISTS pbjt_tagihan (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            wp_id VARCHAR(32) NOT NULL,
            masa CHAR(7) NOT NULL,
            pajak BIGINT NOT NULL,
            status ENUM('terbit','lunas') NOT NULL DEFAULT 'terbit',
            jatuh_tempo DATE NOT NULL,
            snap_va_id BIGINT NULL,
            va_no VARCHAR(28) NULL,
            dibayar BIGINT NULL,
            lunas_pada DATETIME NULL,
            dibuat_oleh VARCHAR(80) NOT NULL,
            dibuat_pada DATETIME NOT NULL,
            diubah_pada DATETIME NOT NULL,
            UNIQUE KEY uq_wp_masa (wp_id, masa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!$ok) throw new RuntimeException('Migrasi pbjt_tagihan gagal: ' . $db->error);
    }

    /** Masa YYYY-MM valid dan sudah berakhir (tagihan hanya untuk masa yang selesai). */
    public static function masaSelesai($masa)
    {
        return is_string($masa) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $masa) && $masa < date('Y-m');
    }

    public function pajakTerutang($wp, $masa)
    {
        $first = $masa . '-01';
        $last = date('Y-m-t', strtotime($first));
        $st = $this->db->prepare("SELECT COALESCE(SUM(pajak_terlapor),0) FROM pbjt_omzet_harian WHERE wp_id=? AND tanggal BETWEEN ? AND ?");
        $st->bind_param('sss', $wp, $first, $last);
        $st->execute();
        $v = (int) $st->get_result()->fetch_row()[0];
        $st->close();
        return $v;
    }

    public function jatuhTempo($masa)
    {
        $next = strtotime($masa . '-01 +1 month');
        $day = min((int) $this->cfg['tanggal_jatuh_tempo'], (int) date('t', $next));
        return date('Y-m-', $next) . sprintf('%02d', $day);
    }

    public function get($id)
    {
        $st = $this->db->prepare("SELECT * FROM pbjt_tagihan WHERE id=?");
        $st->bind_param('i', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: null;
    }

    public function find($wp, $masa)
    {
        $st = $this->db->prepare("SELECT * FROM pbjt_tagihan WHERE wp_id=? AND masa=?");
        $st->bind_param('ss', $wp, $masa);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: null;
    }

    /**
     * Terbitkan (atau perbarui) tagihan WP untuk masa, lalu terbitkan VA di bank.
     * Mengembalikan [tagihan, vaRow]. Melempar exception bila tidak bisa ditagih.
     */
    public function terbitkan($wp, $masa, Snap_service $snap, $profile, $aktor)
    {
        if (!self::masaSelesai($masa)) {
            throw new InvalidArgumentException("Masa $masa belum berakhir; tagihan diterbitkan setelah masa selesai.");
        }
        $st = $this->db->prepare("SELECT nama FROM pbjt_wp WHERE wp_id=?");
        $st->bind_param('s', $wp);
        $st->execute();
        $w = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$w) throw new InvalidArgumentException("WP $wp tidak terdaftar.");

        $pajak = $this->pajakTerutang($wp, $masa);
        if ($pajak <= 0) throw new InvalidArgumentException("Tidak ada pajak terutang untuk $wp masa $masa.");

        $t = $this->find($wp, $masa);
        if ($t && $t['status'] === 'lunas') throw new RuntimeException("Tagihan $wp masa $masa sudah lunas.");

        $now = date('Y-m-d H:i:s');
        $jt = $this->jatuhTempo($masa);
        if ($t) {
            $st = $this->db->prepare("UPDATE pbjt_tagihan SET pajak=?, jatuh_tempo=?, diubah_pada=? WHERE id=?");
            $st->bind_param('issi', $pajak, $jt, $now, $t['id']);
        } else {
            $st = $this->db->prepare("INSERT INTO pbjt_tagihan (wp_id,masa,pajak,status,jatuh_tempo,dibuat_oleh,dibuat_pada,diubah_pada) VALUES (?,?,?,'terbit',?,?,?,?)");
            $st->bind_param('ssissss', $wp, $masa, $pajak, $jt, $aktor, $now, $now);
        }
        $st->execute();
        $id = $t ? (int) $t['id'] : $st->insert_id;
        $st->close();

        $va = $snap->issue($profile, 'pbjt', null, (string) $id, 'PBJT ' . $w['nama'], $pajak, $this->batasVa($masa));
        if ($va['status'] === 'pending') {
            $st = $this->db->prepare("UPDATE pbjt_tagihan SET snap_va_id=?, va_no=?, diubah_pada=? WHERE id=?");
            $vaNo = trim($va['va_no']);
            $st->bind_param('issi', $va['id'], $vaNo, $now, $id);
            $st->execute();
            $st->close();
        }
        $this->audit($aktor, 'terbit-tagihan', "$wp masa $masa Rp $pajak; VA " . trim($va['va_no']) . " ({$va['status']})");
        return [$this->get($id), $va];
    }

    /**
     * Dipanggil handler callback saat VA lunas. Idempoten.
     * Menolak bila VA bukan VA aktif tagihan itu (mis. VA lama yang sudah diganti).
     */
    public function tandaiLunas(array $va)
    {
        $t = $this->get((int) $va['ref']);
        if (!$t) throw new RuntimeException("Tagihan #{$va['ref']} tidak ditemukan");
        if ($t['status'] === 'lunas') return false;
        if ((int) $t['snap_va_id'] !== (int) $va['id']) {
            throw new RuntimeException("VA #{$va['id']} bukan VA aktif tagihan #{$t['id']}");
        }
        $paid = (int) round((float) $va['paid_amount']);
        $at = $va['paid_at'] ?: date('Y-m-d H:i:s');
        $now = date('Y-m-d H:i:s');
        $st = $this->db->prepare("UPDATE pbjt_tagihan SET status='lunas', dibayar=?, lunas_pada=?, diubah_pada=? WHERE id=? AND status='terbit'");
        $st->bind_param('issi', $paid, $at, $now, $t['id']);
        $st->execute();
        $changed = $st->affected_rows === 1;
        $st->close();
        if ($changed) {
            $this->audit('bank#' . $va['profile'], 'tagihan-lunas', "{$t['wp_id']} masa {$t['masa']} Rp $paid via VA " . trim($va['va_no']));
        }
        return $changed;
    }

    private function audit($aktor, $aksi, $detail)
    {
        $st = $this->db->prepare("INSERT INTO pbjt_audit_log (waktu,aktor,aksi,detail) VALUES (?,?,?,?)");
        if (!$st) return;
        $now = date('Y-m-d H:i:s');
        $detail = mb_substr($detail, 0, 255);
        $st->bind_param('ssss', $now, $aktor, $aksi, $detail);
        $st->execute();
        $st->close();
    }
}
