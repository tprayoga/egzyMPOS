<?php
/**
 * PBJT kamar per malam + STPD (CLI):  php tests/pbjt_kamar_stpd_test.php
 *
 *  1. Rumus Pbjt_pajak (kamar): 1 malam, 3 malam, harga belum termasuk pajak, pembulatan sekali.
 *  2. Sinkron kolom booking di DB hotel uji `snap_test_hotel` (dibuat tests/snapbi/booking_va_test.php).
 *  3. Regresi TC-046: omzet & pajak di pengawasan = invoice checkout (total_price x malam).
 *  4. STPD: bulan keterlambatan, bunga, batas 24 bulan, dokumen ber-watermark DRAFT.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PBJT_CLI', true);
define('BASEPATH', __DIR__); // izinkan view dimuat langsung

$root = dirname(__DIR__);
require __DIR__ . '/snapbi/_helpers.php';
require $root . '/application/modules/pbjt/libraries/Pbjt_pajak.php';
require $root . '/application/modules/pbjt/libraries/Pbjt_engine.php';
require $root . '/application/modules/pbjt/libraries/Pbjt_tagihan.php';
require $root . '/application/modules/pbjt/libraries/Pbjt_stpd.php';
$c = require $root . '/application/config/pbjt_db.php';
$HOTEL = 'snap_test_hotel';
$run = getmypid();

echo "1. Rumus PBJT kamar\n";
$h = Pbjt_pajak::kamar(550000, 1, 0.10, true);
check('1 malam, termasuk pajak: bruto 550.000, pbjt 50.000, dpp 500.000',
    $h['bruto'] == 550000 && $h['pbjt'] == 50000 && $h['dpp'] == 500000, json_encode($h));
$h = Pbjt_pajak::kamar(780000, 3, 0.10, true);
check('3 malam, termasuk pajak: bruto 2.340.000, pbjt 212.727, dpp 2.127.273',
    $h['bruto'] == 2340000 && $h['pbjt'] == 212727 && $h['dpp'] == 2127273 && $h['jumlah_malam'] === 3, json_encode($h));
$h = Pbjt_pajak::kamar(500000, 2, 0.10, false);
check('harga belum termasuk pajak: dpp = bruto 1.000.000, pbjt 100.000',
    $h['dpp'] == 1000000 && $h['bruto'] == 1000000 && $h['pbjt'] == 100000, json_encode($h));
$h = Pbjt_pajak::kamar(100005, 3, 0.10, true);
$perMalam = 3 * round(100005 - 100005 / 1.1);
check('dibulatkan sekali di level total (bukan per malam)', $h['pbjt'] == 27274 && $perMalam == 27273, "total={$h['pbjt']} per-malam=$perMalam");
check('dpp + pbjt = bruto', Pbjt_pajak::kamar(123457, 4, 0.10, true)['dpp'] + Pbjt_pajak::kamar(123457, 4, 0.10, true)['pbjt'] == 493828);
check('jumlah malam dari tanggal kalender', Pbjt_pajak::jumlahMalam('2026-12-20 14:00:00', '2026-12-22 12:00:00') === 2
    && Pbjt_pajak::jumlahMalam('2026-12-20 14:00:00', '2026-12-20 18:00:00') === 1);

// ---------------------------------------------------------------- 2-3. DB hotel uji + engine
$db = @new mysqli($c['host'], $c['user'], $c['pass'], $HOTEL);
if ($db->connect_errno || !$db->query("SELECT 1 FROM booked_info LIMIT 1")) {
    fwrite(STDERR, "DB $HOTEL belum ada. Jalankan dulu: php tests/snapbi/booking_va_test.php\n");
    exit(1);
}
$db->query("SET SESSION sql_mode=''"); // sama dengan koneksi aplikasi (stricton FALSE)
$db->multi_query(file_get_contents($root . '/application/migrations_pbjt/009_pbjt_kamar_per_malam.sql'));
while ($db->more_results() && $db->next_result()) {}
$ctl = test_db($root);
foreach (['tenants'] as $t) $ctl->query("CREATE TABLE IF NOT EXISTS $t LIKE `{$c['control_db']}`.$t");
$ctl->query("REPLACE INTO tenants (wp_id,nama,db_name,jenis_usaha,kecamatan,status,dibuat) VALUES ('TSTHTL','Hotel Uji','$HOTEL','hotel','Soreang',1,NOW())");
$ctl->query("CREATE TABLE IF NOT EXISTS pbjt_omzet_harian LIKE `{$c['oversight_db']}`.pbjt_omzet_harian");

$tgl = '2031-01-15'; // tanggal unik agar tak bercampur booking uji lain
$db->query("INSERT INTO roomdetails (roomtype, rate, price_includes_tax) VALUES ('Uji Incl $run', 780000, 1), ('Uji Excl $run', 500000, 0)");
$rIncl = $db->insert_id; $rExcl = $rIncl + 1;
$ins = function ($room, $harga, $in, $out, $status = 0) use ($db, $tgl, $run) {
    $db->query("INSERT INTO booked_info (booking_number, date_time, roomid, room_no, total_price, checkindate, checkoutdate, bookingstatus, full_guest_name)
        VALUES ('U$run', '$tgl 10:00:00', '$room', '999', $harga, '$in 14:00:00', '$out 12:00:00', $status, 'Uji PBJT')") or die($db->error);
    return $db->insert_id;
};
try {
    echo "2. Sinkron kolom booking\n";
    $b1 = $ins($rIncl, 780000, '2031-02-01', '2031-02-04');       // 3 malam, termasuk pajak
    $b2 = $ins($rExcl, 500000, '2031-02-01', '2031-02-03');       // 2 malam, belum termasuk pajak
    $b3 = $ins($rIncl, 550000, '2031-02-05', '2031-02-06', 1);    // batal: bukan omzet
    Pbjt_pajak::sinkronKamar($db, $HOTEL, 0.10);
    $row = function ($id) use ($db) { return $db->query("SELECT * FROM booked_info WHERE bookedid=$id")->fetch_assoc(); };
    $x = $row($b1);
    check('tersimpan: harga_per_malam, jumlah_malam, bruto, dpp, pbjt, tarif_snapshot',
        $x['harga_per_malam'] == 780000 && $x['jumlah_malam'] == 3 && $x['bruto'] == 2340000 && $x['dpp'] == 2127273 && $x['pbjt'] == 212727 && $x['tarif_snapshot'] == 0.1, json_encode($x));
    $x = $row($b2);
    check('listing price_includes_tax=0: dpp = bruto, pbjt = dpp x tarif', $x['dpp'] == 1000000 && $x['pbjt'] == 100000, json_encode($x));
    Pbjt_pajak::sinkronKamar($db, $HOTEL, 0.12);
    check('tarif berubah: booking yang tak berubah memakai snapshot lama', $row($b1)['pbjt'] == 212727 && $row($b1)['tarif_snapshot'] == 0.1);
    $db->query("UPDATE booked_info SET checkoutdate='2031-02-05 12:00:00' WHERE bookedid=$b1");
    Pbjt_pajak::sinkronKamar($db, $HOTEL, 0.10);
    check('booking diubah (malam 3 -> 4): dihitung ulang', $row($b1)['jumlah_malam'] == 4 && $row($b1)['bruto'] == 3120000, json_encode($row($b1)));

    echo "3. Regresi TC-046 (engine = invoice)\n";
    $engine = new Pbjt_engine($ctl, ['control_db' => 'snap_test', 'tarif_pbjt' => 0.10]);
    $ctl->query("DELETE FROM pbjt_omzet_harian WHERE wp_id='TSTHTL' AND tanggal='$tgl'");
    $engine->syncOmzetFromTenants(substr($tgl, 0, 7));
    $o = $ctl->query("SELECT omzet_bruto, pajak_terlapor FROM pbjt_omzet_harian WHERE wp_id='TSTHTL' AND tanggal='$tgl' AND device_id='APP'")->fetch_assoc();
    // Omzet = dpp + pbjt (yang dibayar tamu): termasuk pajak = total_price x malam; belum termasuk = + PBJT.
    $invoice = 780000 * 4 + (500000 * 2 + 100000);
    $pajakExp = Pbjt_pajak::kamar(780000, 4, 0.10, true)['pbjt'] + Pbjt_pajak::kamar(500000, 2, 0.10, false)['pbjt'];
    check('omzet pengawasan = total yang dibayar tamu (booking batal tidak dihitung)', $o && (int) $o['omzet_bruto'] === $invoice, json_encode($o) . " invoice=$invoice");
    check('pajak pengawasan = jumlah PBJT per booking', $o && (int) $o['pajak_terlapor'] === (int) $pajakExp, json_encode($o) . " pajak=$pajakExp");
    $x1 = $row($b1); $x2 = $row($b2);
    check('tarif termasuk pajak: dasar pengenaan + pajak = invoice', (int) round($x1['dpp'] + $x1['pbjt']) === 780000 * 4, json_encode($x1));
    check('tarif belum termasuk pajak: dasar pengenaan = invoice, PBJT di atasnya', (int) $x2['dpp'] === 500000 * 2 && (int) $x2['pbjt'] === 100000, json_encode($x2));
} finally {
    $db->query("DELETE FROM booked_info WHERE booking_number='U$run'");
    $db->query("DELETE FROM roomdetails WHERE roomid IN ($rIncl,$rExcl)");
    $ctl->query("DELETE FROM pbjt_omzet_harian WHERE wp_id='TSTHTL' AND tanggal='$tgl'");
}

// ---------------------------------------------------------------- 4. STPD
echo "4. STPD\n";
$b = function ($jt, $tg, $parsial = true, $maks = 24) { return Pbjt_stpd::bulanTerlambat($jt, $tg, $parsial, $maks); };
check('tepat jatuh tempo: 0 bulan', $b('2026-09-10', '2026-09-10') === 0);
check('lewat 1 hari: 1 bulan (bulan parsial dihitung penuh)', $b('2026-09-10', '2026-09-11') === 1);
check('tepat 1 bulan: 1 bulan', $b('2026-09-10', '2026-10-10') === 1);
check('1 bulan + 1 hari: 2 bulan', $b('2026-09-10', '2026-10-11') === 2);
check('bulan parsial tidak dihitung bila config = false', $b('2026-09-10', '2026-10-11', false) === 1);
check('dibatasi maks 24 bulan', $b('2026-09-10', '2030-01-01') === 24);

$sd = "stpd_test_$run";
$m = new mysqli($c['host'], $c['user'], $c['pass']);
$m->query("CREATE DATABASE `$sd` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$m->select_db($sd);
try {
    Pbjt_tagihan::migrate($m);
    $m->query("CREATE TABLE pbjt_wp (wp_id VARCHAR(32) PRIMARY KEY, nama VARCHAR(150), jenis_usaha VARCHAR(10), kecamatan VARCHAR(80)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $m->query("INSERT INTO pbjt_wp VALUES ('WP008','Hotel Cempaka','hotel','Sukajadi')");
    $now = '2026-10-09 10:00:00';
    $m->query("INSERT INTO pbjt_tagihan (wp_id,masa,pajak,status,jatuh_tempo,dibayar,lunas_pada,dibuat_oleh,dibuat_pada,diubah_pada) VALUES
        ('WP008','2026-08',2000000,'terbit','2026-09-10',NULL,NULL,'t','$now','$now'),
        ('WP008','2026-07',1500000,'lunas','2026-08-10',1500000,'2026-08-25 09:00:00','t','$now','$now'),
        ('WP008','2026-06',1000000,'lunas','2026-07-10',1000000,'2026-07-09 09:00:00','t','$now','$now')");
    $stpd = new Pbjt_stpd($m);
    $cfg = $stpd->config();
    check('config default: 1%/bulan, maks 24, parsial penuh, belum diverifikasi',
        (float) $cfg['bunga_per_bulan'] === 0.01 && (int) $cfg['maks_bulan'] === 24 && (int) $cfg['bulan_parsial_dihitung_penuh'] === 1
        && (int) $cfg['terverifikasi'] === 0 && strpos($cfg['catatan'], 'BELUM DIVERIFIKASI') === 0, json_encode($cfg));

    $id = $stpd->terbitkan(1, 'bapenda#1', $now);
    $s = $stpd->get($id);
    check('belum dibayar: acuan = tanggal terbit, 1 bulan, bunga 1% x 2.000.000, total = pokok + bunga',
        $s['jenis_acuan'] === 'terbit' && (int) $s['jumlah_bulan'] === 1 && (int) $s['bunga'] === 20000
        && (int) $s['pokok_kurang_bayar'] === 2000000 && (int) $s['total'] === 2020000 && $s['nomor'] === 'STPD/0001/2026', json_encode($s));
    $id2 = $stpd->terbitkan(2, 'bapenda#1', $now);
    $s2 = $stpd->get($id2);
    check('lunas terlambat: acuan = tanggal bayar, pokok kurang bayar 0, bunga 15.000',
        $s2['jenis_acuan'] === 'bayar' && $s2['tanggal_acuan'] === '2026-08-25' && (int) $s2['pokok_kurang_bayar'] === 0
        && (int) $s2['bunga'] === 15000 && (int) $s2['total'] === 15000, json_encode($s2));
    $tolak = false;
    try { $stpd->terbitkan(3, 'bapenda#1', $now); } catch (InvalidArgumentException $e) { $tolak = true; }
    check('dibayar sebelum jatuh tempo: STPD ditolak', $tolak);
    check('snapshot config tersimpan di STPD', json_decode($s['config_snapshot'], true)['bunga_per_bulan'] == 0.01);

    $render = function ($s) {
        ob_start();
        $cfg = json_decode($s['config_snapshot'], true);
        $alamat = null;
        include dirname(__DIR__) . '/application/modules/pbjt/views/stpd_dokumen.php';
        return ob_get_clean();
    };
    $html = $render($s);
    foreach (['Hotel Cempaka', 'WP008', 'Agustus 2026', '10 September 2026', '9 Oktober 2026', 'Rp 2.000.000', 'Rp 20.000', 'Rp 2.020.000', '1 bulan', 'STPD/0001/2026'] as $needle) {
        check("dokumen memuat \"$needle\"", strpos($html, $needle) !== false);
    }
    check('watermark DRAFT selama config belum diverifikasi', strpos($html, 'class="wm">DRAFT') !== false && strpos($html, 'BELUM DIVERIFIKASI') !== false);
    $m->query("UPDATE pbjt_sanksi_config SET terverifikasi=1, catatan='Terverifikasi uji'");
    $s3 = $stpd->get($stpd->terbitkan(1, 'bapenda#1', $now));
    check('tanpa watermark setelah config diverifikasi', strpos($render($s3), 'class="wm"') === false);
} finally {
    $m->query("DROP DATABASE `$sd`");
}
test_done();
