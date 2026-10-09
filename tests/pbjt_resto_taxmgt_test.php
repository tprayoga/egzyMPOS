<?php
/**
 * PBJT restoran (engine yang sama dengan kamar) + pembatasan Tax Management (CLI):
 *   php tests/pbjt_resto_taxmgt_test.php
 *
 *  1. Rumus restoran: harga exclusive/inclusive, dengan & tanpa service charge.
 *  2. Sinkron restoran di DB hotel uji `snap_test_hotel`: dpp/pbjt/tarif per transaksi; bill belum
 *     dibayar diselaraskan ke PBJT engine; bill lunas tidak diubah nominalnya.
 *  3. Migrasi 010: entri pajak daerah di Tax Management dinonaktifkan + dicatat.
 *  4. HTTP: Tax Management menolak entri PBJT/PB1; listing kamar flag false + entri pajak di Tax
 *     Management -> PBJT hanya terhitung sekali.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PBJT_CLI', true);

$root = dirname(__DIR__);
require __DIR__ . '/snapbi/_helpers.php';
require $root . '/application/helpers/egzy_auth_helper.php';
require $root . '/application/modules/pbjt/libraries/Pbjt_pajak.php';
$c = require $root . '/application/config/pbjt_db.php';
$HOTEL = 'snap_test_hotel';
$PORT = 8799;
$run = getmypid();
$T = Pbjt_pajak::tarif();

echo "1. Rumus restoran (satu engine)\n";
check('tarif dari config Pemda = 10%', $T === 0.1, (string) $T);
$h = Pbjt_pajak::hitung(100000, 0, 0.10, false);
check('exclusive tanpa service: dpp 100.000, pbjt 10.000, total 110.000', $h['dpp'] == 100000 && $h['pbjt'] == 10000 && $h['total'] == 110000, json_encode($h));
$h = Pbjt_pajak::hitung(100000, 5000, 0.10, false);
check('exclusive + service charge: service masuk DPP (105.000), pbjt 10.500, total 115.500', $h['dpp'] == 105000 && $h['pbjt'] == 10500 && $h['total'] == 115500, json_encode($h));
$h = Pbjt_pajak::hitung(110000, 0, 0.10, true);
check('inclusive tanpa service: dpp 100.000, pbjt 10.000, total = bruto 110.000', $h['dpp'] == 100000 && $h['pbjt'] == 10000 && $h['total'] == 110000, json_encode($h));
$h = Pbjt_pajak::hitung(110000, 5500, 0.10, true);
check('inclusive + service charge: bruto 115.500 -> dpp 105.000, pbjt 10.500', $h['dpp'] == 105000 && $h['pbjt'] == 10500 && $h['total'] == 115500, json_encode($h));
$h = Pbjt_pajak::hitung(33335, 0, 0.10, false);
check('dibulatkan sekali ke rupiah: 33.335 x 10% = 3.334', $h['pbjt'] == 3334 && $h['total'] == 36669, json_encode($h));
check('kamar & restoran memakai fungsi yang sama', Pbjt_pajak::kamar(550000, 1, 0.10, true)['pbjt'] === Pbjt_pajak::hitung(550000, 0, 0.10, true)['pbjt']);
foreach (['PBJT Hotel' => true, 'PB1' => true, 'Pajak Restoran' => true, 'Hotel Tax' => true, 'pajak daerah 10%' => true,
          'Service Charge' => false, 'Biaya Layanan' => false, 'Pajak Hiburan' => false] as $nama => $tolak) {
    check(($tolak ? 'ditolak' : 'diizinkan') . " di Tax Management: \"$nama\"", Pbjt_pajak::adalahPajakDaerah($nama) === $tolak);
}

// ---------------------------------------------------------------- DB hotel uji
$db = @new mysqli($c['host'], $c['user'], $c['pass'], $HOTEL);
if ($db->connect_errno || !$db->query("SELECT 1 FROM customer_order LIMIT 1")) {
    fwrite(STDERR, "DB $HOTEL belum ada. Jalankan dulu: php tests/snapbi/booking_va_test.php\n");
    exit(1);
}
$db->query("SET SESSION sql_mode=''"); // sama dengan koneksi aplikasi (stricton FALSE)
$mig = function ($v) use ($db, $root) {
    $db->multi_query(file_get_contents(glob("$root/application/migrations_pbjt/{$v}_*.sql")[0]));
    while ($db->more_results() && $db->next_result()) {}
    return $db->error;
};
foreach (['004', '005', '006', '009', '010'] as $v) $mig($v);
$inclAwal = (int) $db->query("SELECT resto_price_includes_tax FROM setting LIMIT 1")->fetch_row()[0];
$order = function ($sub, $disc, $sc, $vatKlien, $status) use ($db, $run) {
    $db->query("INSERT INTO customer_order (saleinvoice, customer_id, order_date, order_time, totalamount, order_status, tokenno)
        VALUES ('R$run', 0, '2031-03-01', '12:00:00', " . ($sub - $disc + $sc + $vatKlien) . ", 4, '0')") or die($db->error);
    $oid = $db->insert_id;
    $db->query("INSERT INTO bill (customer_id, order_id, total_amount, discount, service_charge, VAT, bill_amount, bill_date, bill_status)
        VALUES (0, $oid, $sub, $disc, $sc, $vatKlien, " . ($sub - $disc + $sc + $vatKlien) . ", '2031-03-01', $status)") or die($db->error);
    return $oid;
};
$get = function ($oid) use ($db) {
    return $db->query("SELECT o.dpp, o.pbjt, o.tarif_snapshot, o.totalamount, b.VAT, b.bill_amount FROM customer_order o JOIN bill b ON b.order_id=o.order_id WHERE o.order_id=$oid")->fetch_assoc();
};
try {
    echo "2. Sinkron restoran\n";
    $db->query("UPDATE setting SET resto_price_includes_tax=0");
    $a = $order(100000, 0, 5000, 2100, 0);    // belum bayar; VAT klien lama (2% dari setting.vat)
    $b = $order(100000, 0, 0, 0, 1);          // sudah lunas; VAT klien 0
    Pbjt_pajak::sinkronResto($db, $HOTEL, 0.10, true);
    $x = $get($a);
    check('belum bayar: dpp 105.000, pbjt 10.500, tarif_snapshot tersimpan', $x['dpp'] == 105000 && $x['pbjt'] == 10500 && $x['tarif_snapshot'] == 0.1, json_encode($x));
    check('belum bayar: VAT bill diselaraskan ke PBJT engine, total 115.500', $x['VAT'] == 10500 && $x['bill_amount'] == 115500 && $x['totalamount'] == 115500, json_encode($x));
    $y = $get($b);
    check('lunas: PBJT engine tercatat (10.000), nominal bill tidak diubah', $y['pbjt'] == 10000 && $y['VAT'] == 0 && $y['bill_amount'] == 100000, json_encode($y));
    $db->query("UPDATE bill SET discount=10000 WHERE order_id=$a");
    Pbjt_pajak::sinkronResto($db, $HOTEL, 0.10, true);
    check('diskon diubah: dihitung ulang (dpp 95.000, pbjt 9.500)', $get($a)['pbjt'] == 9500 && $get($a)['bill_amount'] == 104500, json_encode($get($a)));

    $db->query("UPDATE setting SET resto_price_includes_tax=1");
    $c2 = $order(110000, 0, 0, 11000, 0);
    Pbjt_pajak::sinkronResto($db, $HOTEL, 0.10, true);
    $z = $get($c2);
    check('outlet harga termasuk pajak: VAT tidak ditambahkan, total = bruto, pbjt 10.000 di dalamnya',
        $z['VAT'] == 0 && $z['bill_amount'] == 110000 && $z['pbjt'] == 10000 && $z['dpp'] == 100000, json_encode($z));
    $n = Pbjt_pajak::sinkronResto($db, $HOTEL, 0.10, true);
    check('sinkron idempoten (tidak ada perubahan berikutnya)', $n === 0, "$n baris");
} finally {
    $db->query("UPDATE setting SET resto_price_includes_tax=$inclAwal");
    $db->query("DELETE b FROM bill b JOIN customer_order o ON o.order_id=b.order_id WHERE o.saleinvoice='R$run'");
    $db->query("DELETE FROM customer_order WHERE saleinvoice='R$run'");
}

echo "3. Migrasi 010: Tax Management\n";
$db->query("INSERT INTO tbl_taxmgt (taxname, rate, reg_no, isactive) VALUES ('PBJT Hotel $run', 10, '001', 1), ('Service Charge $run', 5, '', 1)");
$e = $mig('010');
check('migrasi 010 jalan ulang (idempoten)', $e === '', $e);
$st = function ($nama) use ($db) { return (int) $db->query("SELECT isactive FROM tbl_taxmgt WHERE taxname='$nama'")->fetch_row()[0]; };
check('entri pajak daerah dinonaktifkan', $st("PBJT Hotel $run") === 0);
check('entri non-PBJT (service charge) tetap aktif', $st("Service Charge $run") === 1);
check('nilai lama dicatat di pbjt_taxmgt_nonaktif', (int) $db->query("SELECT COUNT(*) FROM pbjt_taxmgt_nonaktif WHERE taxname='PBJT Hotel $run' AND rate=10")->fetch_row()[0] === 1);
check('dicatat di accesslog', (int) $db->query("SELECT COUNT(*) FROM accesslog WHERE remarks LIKE '%PBJT Hotel $run%'")->fetch_row()[0] >= 1);

// ---------------------------------------------------------------- 4. HTTP
echo "4. HTTP: Tax Management & booking kamar\n";
$ctl = test_db($root);
foreach (['tenants', 'directory'] as $t) $ctl->query("CREATE TABLE IF NOT EXISTS $t LIKE `{$c['control_db']}`.$t");
$ctl->query("REPLACE INTO tenants (wp_id,nama,db_name,jenis_usaha,kecamatan,status,dibuat) VALUES ('TSTHTL','Hotel Uji','$HOTEL','hotel','Soreang',1,NOW())");
$admin = "pjk$run@uji.test";
$db->query("INSERT INTO user (firstname,lastname,email,password,status,usertype,is_admin) VALUES ('Adm','Uji','$admin','" . egzy_password_hash('UjiPajak123') . "',1,1,1)") or die($db->error);
$ctl->query("REPLACE INTO directory (email,wp_id,db_name,is_bapenda) VALUES ('$admin','TSTHTL','$HOTEL',0)");
$db->query("INSERT INTO roomdetails (roomtype, rate, capacity, price_includes_tax) VALUES ('Excl $run', 500000, 2, 0)");
$room = $db->insert_id;
$rno = 90000 + $run % 10000; // aplikasi mengasumsikan nomor kamar numerik
$db->query("INSERT INTO tbl_roomnofloorassign (roomno, roomid, status) VALUES ('$rno', $room, 0)");

$tmp = test_tmpdir();
$app = proc_open("exec env PBJT_CONTROL_DB=snap_test php -S 127.0.0.1:$PORT " . escapeshellarg("$root/server_router.php"),
    [0 => ['pipe', 'r'], 1 => ['file', "$tmp/app.log", 'a'], 2 => ['file', "$tmp/app.log", 'a']], $pp, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $PORT); $i++) usleep(100000);
$jar = "$tmp/j";
touch($jar);
$req = function ($method, $path, $post = null, &$hdr = null) use ($PORT, $jar) {
    $ch = curl_init("http://127.0.0.1:$PORT$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true]);
    if ($post !== null) {
        $post['csrf_test_name'] = preg_match('/\tcsrf_cookie_name\t(\S+)/', (string) file_get_contents($jar), $m) ? $m[1] : '';
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $hdr = substr($raw, 0, $hs);
    return [$code, substr($raw, $hs)];
};
try {
    $req('GET', '/login');
    $sid = preg_match('/\tci_session\t(\S+)/', file_get_contents($jar), $m) ? $m[1] : '';
    $cap = preg_match('/captcha\|s:\d+:"([^"]*)"/', (string) @file_get_contents("$root/application/cache/temp/ci_session$sid"), $m) ? $m[1] : '';
    $hdr = null;
    list($code) = $req('POST', '/login', ['email' => $admin, 'password' => 'UjiPajak123', 'captcha' => $cap], $hdr);
    check('login admin tenant uji', $code === 302 && preg_match('/Location: \S*dashboard/i', $hdr));

    $req('POST', '/tax_management/tax/create', ['tax' => "PB1 Restoran $run", 'rate' => 10, 'reg_no' => '', 'isactive' => 1]);
    check('Tax Management menolak entri "PB1 Restoran"', (int) $db->query("SELECT COUNT(*) FROM tbl_taxmgt WHERE taxname='PB1 Restoran $run'")->fetch_row()[0] === 0);
    check('penolakan dicatat di accesslog', (int) $db->query("SELECT COUNT(*) FROM accesslog WHERE action_done='Ditolak' AND remarks LIKE '%PB1 Restoran $run%'")->fetch_row()[0] === 1);
    $req('POST', '/tax_management/tax/create', ['tax' => "Biaya Layanan $run", 'rate' => 3, 'reg_no' => '', 'isactive' => 0]);
    check('Tax Management menerima komponen non-PBJT', (int) $db->query("SELECT COUNT(*) FROM tbl_taxmgt WHERE taxname='Biaya Layanan $run'")->fetch_row()[0] === 1);

    // Kondisi double-count lama: listing flag false + entri "PBJT Hotel" di Tax Management.
    // Setelah migrasi 010 entri itu nonaktif; hanya "Service Charge 5%" (non-PBJT) yang ikut harga.
    $aktif = (float) $db->query("SELECT COALESCE(SUM(rate),0) FROM tbl_taxmgt WHERE isactive=1")->fetch_row()[0];
    list($code, $body) = $req('POST', '/room_reservation/room_reservation/newBooking', [
        'datefilter1' => '2031-04-01 14:00', 'datefilter2' => '2031-04-03 12:00', 'room_type' => $room, 'roomno' => (string) $rno,
        'adults' => 2, 'children' => 0, 'rent' => 500000, 'discount_price' => 0, 'complementary' => 'no', 'userid' => '',
        'name' => "Tamu$run", 'mobile' => '0812' . $run, 'email' => "t$run@uji.test", 'lastname' => 'Uji', 'gender' => 'Male',
        'pid' => '', 'bed' => 0, 'person' => 0, 'child' => 0, 'amount3' => 0, 'extrastart' => '', 'extraend' => '', 'advanceamount' => '']);
    check('booking listing exclusive tersimpan', strpos($body, 'Success') !== false, "HTTP $code " . substr(strip_tags($body), 0, 160));
    $bk = $db->query("SELECT * FROM booked_info WHERE full_guest_name='Tamu$run'")->fetch_assoc();
    $hargaExp = 500000 * (1 + $aktif / 100);
    check('harga per malam hanya memuat komponen non-PBJT (tanpa PBJT dari Tax Management)', $bk && abs($bk['total_price'] - $hargaExp) < 0.01,
        ($bk ? $bk['total_price'] : 'null') . " vs $hargaExp (Tax Management aktif $aktif%)");
    $exp = Pbjt_pajak::kamar($hargaExp, 2, $T, false);
    check('PBJT hanya terhitung sekali: pbjt = dpp x 10%, dpp = harga x malam', $bk && $bk['pbjt'] == $exp['pbjt'] && $bk['dpp'] == $exp['dpp'] && $bk['dpp'] == $hargaExp * 2,
        $bk ? json_encode(['dpp' => $bk['dpp'], 'pbjt' => $bk['pbjt']]) : 'null');
} finally {
    proc_terminate($app); proc_close($app);
    $db->query("DELETE FROM booked_details WHERE bookedid IN (SELECT bookedid FROM booked_info WHERE full_guest_name='Tamu$run')");
    $db->query("DELETE FROM booked_info WHERE full_guest_name='Tamu$run'");
    $db->query("DELETE FROM customerinfo WHERE email='t$run@uji.test'");
    $db->query("DELETE FROM tbl_roomnofloorassign WHERE roomno='$rno'");
    $db->query("DELETE FROM roomdetails WHERE roomid=$room");
    $db->query("DELETE FROM tbl_taxmgt WHERE taxname LIKE '%$run'");
    $db->query("DELETE FROM user WHERE email='$admin'");
    $ctl->query("DELETE FROM directory WHERE email='$admin'");
}
if ($GLOBALS['fail']) echo implode("\n", array_slice(file("$tmp/app.log", FILE_IGNORE_NEW_LINES) ?: [], -10)) . "\n";
test_cleanup($tmp);
test_done();
