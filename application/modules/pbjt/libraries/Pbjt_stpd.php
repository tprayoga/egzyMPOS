<?php
/**
 * STPD (Surat Tagihan Pajak Daerah) atas keterlambatan pembayaran tagihan PBJT.
 *
 * Parameter sanksi dibaca dari tabel pbjt_sanksi_config (DB oversight), BUKAN di-hardcode:
 *   bunga_per_bulan, maks_bulan, bulan_parsial_dihitung_penuh, dasar.
 * Nilai awal (1%/bulan, maks 24 bulan, bulan parsial = penuh, dasar = pajak terutang yang terlambat
 * dibayar) BELUM DIVERIFIKASI terhadap Perda PDRD Mentawai / PP 35/2023. Selama terverifikasi=0,
 * dokumen STPD diberi watermark DRAFT.
 *
 * Kelas plain-PHP (mysqli) agar dipakai controller, CLI, dan test.
 */
class Pbjt_stpd
{
    const CATATAN_DEFAULT = 'BELUM DIVERIFIKASI — menunggu konfirmasi Perda PDRD Mentawai / PP 35/2023';

    /** @var mysqli DB oversight */
    private $db;

    public function __construct(mysqli $oversight)
    {
        $this->db = $oversight;
    }

    public static function migrate(mysqli $db)
    {
        $sql = [
            "CREATE TABLE IF NOT EXISTS pbjt_sanksi_config (
                id TINYINT PRIMARY KEY,
                bunga_per_bulan DECIMAL(6,4) NOT NULL,
                maks_bulan INT NOT NULL,
                bulan_parsial_dihitung_penuh TINYINT(1) NOT NULL,
                dasar VARCHAR(60) NOT NULL,
                terverifikasi TINYINT(1) NOT NULL DEFAULT 0,
                catatan VARCHAR(255) NOT NULL,
                diubah_pada DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            "INSERT IGNORE INTO pbjt_sanksi_config VALUES (1, 0.0100, 24, 1, 'pajak_terutang_terlambat_dibayar', 0, '"
                . $db->real_escape_string(self::CATATAN_DEFAULT) . "', NOW())",
            "CREATE TABLE IF NOT EXISTS pbjt_stpd (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                nomor VARCHAR(40) NOT NULL,
                tagihan_id BIGINT NOT NULL,
                wp_id VARCHAR(32) NOT NULL,
                masa CHAR(7) NOT NULL,
                dasar_bunga BIGINT NOT NULL,
                pokok_kurang_bayar BIGINT NOT NULL,
                jatuh_tempo DATE NOT NULL,
                tanggal_acuan DATE NOT NULL,
                jenis_acuan ENUM('bayar','terbit') NOT NULL,
                jumlah_bulan INT NOT NULL,
                bunga BIGINT NOT NULL,
                total BIGINT NOT NULL,
                config_snapshot TEXT NOT NULL,
                diterbitkan_oleh VARCHAR(80) NOT NULL,
                diterbitkan_pada DATETIME NOT NULL,
                KEY ix_tagihan (tagihan_id), KEY ix_wp (wp_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        ];
        foreach ($sql as $q) {
            if (!$db->query($q)) throw new RuntimeException('Migrasi STPD gagal: ' . $db->error);
        }
    }

    public function config()
    {
        self::migrate($this->db);
        return $this->db->query("SELECT * FROM pbjt_sanksi_config WHERE id=1")->fetch_assoc();
    }

    /**
     * Jumlah bulan keterlambatan dari jatuh tempo s.d. tanggal acuan (bayar/terbit).
     * Tidak terlambat -> 0. Bulan parsial dihitung penuh bila $parsialPenuh. Dibatasi $maks.
     */
    public static function bulanTerlambat($jatuhTempo, $tanggal, $parsialPenuh, $maks)
    {
        $jt = new DateTime(substr($jatuhTempo, 0, 10));
        $tg = new DateTime(substr($tanggal, 0, 10));
        if ($tg <= $jt) return 0;
        $penuh = ((int) $tg->format('Y') - (int) $jt->format('Y')) * 12 + ((int) $tg->format('n') - (int) $jt->format('n'));
        // Ulang tahun bulanan jatuh tempo (hari dipotong ke akhir bulan bila perlu).
        $batas = function ($n) use ($jt) {
            $d = (clone $jt)->modify('first day of +' . $n . ' month');
            $d->setDate((int) $d->format('Y'), (int) $d->format('n'), min((int) $jt->format('j'), (int) $d->format('t')));
            return $d;
        };
        if ($batas($penuh) > $tg) $penuh--;          // bulan penuh yang sudah lewat
        $sisa = $batas($penuh) < $tg;                 // ada bulan parsial
        $n = $penuh + ($sisa && $parsialPenuh ? 1 : 0);
        return max(0, min((int) $maks, $n));
    }

    /**
     * Hitung STPD untuk satu baris pbjt_tagihan pada tanggal terbit tertentu.
     * Lunas -> acuan = tanggal bayar; belum lunas -> acuan = tanggal terbit STPD.
     * Melempar exception bila tagihan tidak terlambat.
     */
    public static function hitung(array $tagihan, array $cfg, $tanggalTerbit)
    {
        $lunas = $tagihan['status'] === 'lunas' && $tagihan['lunas_pada'];
        $acuan = substr($lunas ? $tagihan['lunas_pada'] : $tanggalTerbit, 0, 10);
        $bulan = self::bulanTerlambat($tagihan['jatuh_tempo'], $acuan, (int) $cfg['bulan_parsial_dihitung_penuh'] === 1, (int) $cfg['maks_bulan']);
        if ($bulan <= 0) throw new InvalidArgumentException('Tagihan tidak terlambat dibayar; STPD tidak diperlukan.');
        // Dasar = pajak terutang yang terlambat dibayar (pokok tagihan).
        $dasar = (int) $tagihan['pajak'];
        $kurang = $lunas ? max(0, (int) $tagihan['pajak'] - (int) $tagihan['dibayar']) : (int) $tagihan['pajak'] - (int) ($tagihan['dibayar'] ?: 0);
        $bunga = (int) round($dasar * (float) $cfg['bunga_per_bulan'] * $bulan);
        return [
            'dasar_bunga'        => $dasar,
            'pokok_kurang_bayar' => max(0, $kurang),
            'jatuh_tempo'        => $tagihan['jatuh_tempo'],
            'tanggal_acuan'      => $acuan,
            'jenis_acuan'        => $lunas ? 'bayar' : 'terbit',
            'jumlah_bulan'       => $bulan,
            'bunga'              => $bunga,
            'total'              => max(0, $kurang) + $bunga,
        ];
    }

    /** Terbitkan STPD untuk tagihan; mengembalikan id STPD. */
    public function terbitkan($tagihanId, $aktor, $now = null)
    {
        $now = $now ?: date('Y-m-d H:i:s');
        $cfg = $this->config();
        $st = $this->db->prepare("SELECT * FROM pbjt_tagihan WHERE id=?");
        $st->bind_param('i', $tagihanId);
        $st->execute();
        $t = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$t) throw new InvalidArgumentException('Tagihan tidak ditemukan.');
        $h = self::hitung($t, $cfg, $now);

        $tahun = substr($now, 0, 4);
        $seq = 1 + (int) $this->db->query("SELECT COUNT(*) FROM pbjt_stpd WHERE YEAR(diterbitkan_pada) = " . (int) $tahun)->fetch_row()[0];
        $nomor = sprintf('STPD/%04d/%s', $seq, $tahun);
        $snap = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        $st = $this->db->prepare("INSERT INTO pbjt_stpd (nomor,tagihan_id,wp_id,masa,dasar_bunga,pokok_kurang_bayar,jatuh_tempo,tanggal_acuan,
            jenis_acuan,jumlah_bulan,bunga,total,config_snapshot,diterbitkan_oleh,diterbitkan_pada) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->bind_param('sissiisssiiisss', $nomor, $t['id'], $t['wp_id'], $t['masa'], $h['dasar_bunga'], $h['pokok_kurang_bayar'],
            $h['jatuh_tempo'], $h['tanggal_acuan'], $h['jenis_acuan'], $h['jumlah_bulan'], $h['bunga'], $h['total'], $snap, $aktor, $now);
        if (!$st->execute()) throw new RuntimeException('Simpan STPD gagal: ' . $st->error);
        $id = $st->insert_id;
        $st->close();
        return $id;
    }

    public function get($id)
    {
        self::migrate($this->db);
        $st = $this->db->prepare("SELECT s.*, w.nama, w.jenis_usaha, w.kecamatan FROM pbjt_stpd s LEFT JOIN pbjt_wp w ON w.wp_id = s.wp_id WHERE s.id=?");
        $st->bind_param('i', $id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        return $r ?: null;
    }

    /** STPD terakhir per tagihan: [tagihan_id => row]. */
    public function terakhirPerTagihan(array $tagihanIds)
    {
        $ids = array_filter(array_map('intval', $tagihanIds));
        if (!$ids) return [];
        self::migrate($this->db);
        $out = [];
        $r = $this->db->query("SELECT * FROM pbjt_stpd WHERE tagihan_id IN (" . implode(',', $ids) . ") ORDER BY id");
        while ($x = $r->fetch_assoc()) $out[(int) $x['tagihan_id']] = $x;
        return $out;
    }

    /** Tagihan ini terlambat (lunas setelah jatuh tempo, atau belum lunas dan jatuh tempo lewat)? */
    public static function terlambat(array $t, $today = null)
    {
        if (empty($t['jatuh_tempo'])) return false;
        if ($t['status'] === 'lunas') return !empty($t['lunas_pada']) && substr($t['lunas_pada'], 0, 10) > $t['jatuh_tempo'];
        return ($today ?: date('Y-m-d')) > $t['jatuh_tempo'];
    }
}
