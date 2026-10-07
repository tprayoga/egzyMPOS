<?php
/**
 * Test Fase 3 — VA booking per hotel, END-TO-END lewat aplikasi sungguhan (CLI):
 *   php tests/snapbi/booking_va_test.php
 *
 * Menjalankan instance aplikasi kedua (port 8793) yang diarahkan ke DB uji lewat env:
 *   PBJT_CONTROL_DB=snap_test  (registry tenant & SNAP uji)
 *   SNAP_BI_LOCAL=<tmp>        (profil BNI hotel uji -> bank tiruan port 8794)
 * DB hotel uji `snap_test_hotel` = salinan tenant_template (dibuat sekali).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__, 2);
require __DIR__ . '/_helpers.php';
require $root . '/application/modules/snapbi/libraries/Snap_booking.php';
require $root . '/application/modules/snapbi/libraries/Snap_inbound.php';

$c = require $root . '/application/config/pbjt_db.php';
$HOTEL = 'snap_test_hotel';
$APP = 8793; $BANK = 8794;
$run = getmypid();

// ------------------------------------------------------------ persiapan DB
$ctl = test_db($root);
$store = new Snap_store($ctl);
$store->migrate();
$ctl->query("CREATE TABLE IF NOT EXISTS tenants LIKE `{$c['control_db']}`.tenants") or die($ctl->error);
$ctl->query("REPLACE INTO tenants (wp_id,nama,db_name,jenis_usaha,kecamatan,status,dibuat) VALUES ('TSTHTL','Hotel Uji','$HOTEL','hotel','Soreang',1,NOW())");

$srv = new mysqli($c['host'], $c['user'], $c['pass']);
$srv->query("CREATE DATABASE IF NOT EXISTS $HOTEL CHARACTER SET utf8mb4");
$h = new mysqli($c['host'], $c['user'], $c['pass'], $HOTEL);
if ((int) $h->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$HOTEL'")->fetch_row()[0] === 0) {
    echo "(menyalin tenant_template -> $HOTEL)\n";
    $tables = $h->query("SELECT table_name FROM information_schema.tables WHERE table_schema='tenant_template' AND table_type='BASE TABLE'")->fetch_all();
    foreach ($tables as $t) {
        $t = $t[0];
        $h->query("CREATE TABLE `$t` LIKE tenant_template.`$t`") or die($h->error);
        $h->query("INSERT INTO `$t` SELECT * FROM tenant_template.`$t`") or die($h->error);
    }
}
$h->multi_query(file_get_contents($root . '/application/migrations_pbjt/003_payment_method_va_bni.sql'));
while ($h->more_results() && $h->next_result()) {}

// Tamu uji + booking milik tamu itu, plus tamu lain.
$email = "tamu$run@uji.test"; $email2 = "lain$run@uji.test";
$h->query("INSERT INTO customerinfo (firstname,lastname,email,pass,balance) VALUES ('Tamu','Uji','$email','" . md5('rahasia') . "',0),('Orang','Lain','$email2','" . md5('rahasia') . "',0)") or die($h->error);
$cust = (int) $h->query("SELECT customerid FROM customerinfo WHERE email='$email'")->fetch_row()[0];
$bn = 'U' . $run;
$h->query("INSERT INTO booked_info (booking_number,date_time,roomid,room_no,total_price,paid_amount,full_guest_name,checkindate,checkoutdate,cutomerid,bookingstatus)
           VALUES ('$bn',NOW(),'1','101',1750000,0,'Tamu Uji',DATE_ADD(NOW(),INTERVAL 3 DAY),DATE_ADD(NOW(),INTERVAL 5 DAY),$cust,0)") or die($h->error);
$bookedid = $h->insert_id;
$h->query("INSERT INTO booked_details (bookedid) VALUES ($bookedid)");

// ------------------------------------------------------- kunci & konfigurasi
$tmp = test_tmpdir();
keypair($tmp, 'partner');
keypair($tmp, 'bank');
$profile = test_profile($BANK, $tmp, 'B' . $run . '-');
$cfg = ['profiles' => [$HOTEL => $profile]];
file_put_contents("$tmp/snap_local.php", '<?php return ' . var_export($cfg, true) . ';');

echo "1. Profil per hotel\n";
$full = ['profiles' => [$HOTEL => $profile], 'booking_profiles' => ['tenant_x' => $HOTEL]];
check('hotel dengan kredensial -> profil = nama DB', Snap_booking::profileFor($full, $HOTEL) === $HOTEL);
check('pemetaan eksplisit booking_profiles', Snap_booking::profileFor($full, 'tenant_x') === $HOTEL);
check('hotel tanpa kredensial -> tidak tersedia', Snap_booking::profileFor($full, 'tenant_wp001') === null);

$bankProc = start_mock($BANK, $tmp, 'EGZY-TEST', 'secret-out-123');
$env = 'PBJT_CONTROL_DB=snap_test SNAP_BI_LOCAL=' . escapeshellarg("$tmp/snap_local.php");
$appProc = proc_open("exec env $env php -S 127.0.0.1:$APP " . escapeshellarg("$root/server_router.php"),
    [0 => ['pipe', 'r'], 1 => ['file', "$tmp/app.log", 'a'], 2 => ['file', "$tmp/app.log", 'a']], $p, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $APP); $i++) usleep(100000);

/** HTTP sederhana dengan cookie jar per "browser". */
function http($method, $url, $jar, $post = null, $headers = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
    if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, $body, $loc];
}
function browser($tmp, $name, $hotelDb)
{
    $jar = "$tmp/$name.jar";
    // Pilih instance hotel seperti produksi (cookie tnt, divalidasi ke registry).
    file_put_contents($jar, "127.0.0.1\tFALSE\t/\tFALSE\t0\ttnt\t$hotelDb\n");
    return $jar;
}
function login($base, $jar, $email)
{
    http('GET', "$base/hotel", $jar);
    preg_match('/csrf_cookie_name\s+(\w+)/', file_get_contents($jar), $m);
    return http('POST', "$base/hotel/loginsubmit", $jar, ['email' => $email, 'password' => 'rahasia', 'csrf_test_name' => $m[1] ?? '']);
}

$base = "http://127.0.0.1:$APP";
try {
    echo "2. Halaman bayar tamu\n";
    $anon = browser($tmp, 'anon', $HOTEL);
    list($code, , $loc) = http('GET', "$base/hotel/va/$bn", $anon);
    check('tanpa login -> dialihkan', $code === 302 && strpos($loc, '/va/') === false, "$code $loc");

    $guest = browser($tmp, 'guest', $HOTEL);
    list($code) = login($base, $guest, $email);
    check('tamu login', $code === 302, "HTTP $code");
    list($code, $body) = http('GET', "$base/hotel/va/$bn", $guest);
    $va = $store->findByRef('booking', $bn, $HOTEL);
    check('VA diterbitkan untuk booking (bank tiruan menerima)', $code === 200 && $va && $va['status'] === 'pending', "HTTP $code " . ($va ? $va['status'] . ' ' . $va['bank_response'] : 'no VA') . ' ' . (preg_match('/va-sub">([^<]*)/', $body, $mm) ? $mm[1] : ''));
    check('nominal VA = sisa tagihan booking', $va && (float) $va['amount'] === 1750000.0);
    check('VA milik tenant & profil hotel itu', $va && $va['tenant_db'] === $HOTEL && $va['profile'] === $HOTEL);
    check('halaman menampilkan nomor VA', $va && strpos($body, trim($va['va_no'])) !== false);
    http('GET', "$base/hotel/va/$bn", $guest);
    check('buka ulang memakai VA yang sama', $store->findByRef('booking', $bn, $HOTEL)['id'] === $va['id']);
    list(, $body) = http('GET', "$base/hotel/va_status/$bn", $guest);
    check('status polling: pending', json_decode($body, true) === ['status' => 'pending'], $body);

    $other = browser($tmp, 'other', $HOTEL);
    login($base, $other, $email2);
    list($code, , $loc) = http('GET', "$base/hotel/va/$bn", $other);
    check('tamu lain tidak bisa membuka booking ini', $code === 302, "$code $loc");
    list($code, , $loc) = http('GET', "$base/hotel/va_status/$bn", $other);
    check('tamu lain tidak bisa membaca status', $code === 302, "$code");

    echo "3. Notifikasi bayar dari bank (HTTP ke aplikasi)\n";
    $bankPriv = file_get_contents("$tmp/bank_private.pem");
    $ts = Snap_sign::timestamp();
    list($code, $raw) = http('POST', "$base/v1.0/access-token/b2b", null, '{"grantType":"client_credentials"}', [
        'Content-Type: application/json', "X-TIMESTAMP: $ts", 'X-CLIENT-KEY: BNI-TEST',
        'X-SIGNATURE: ' . Snap_sign::asymmetric('BNI-TEST', $ts, $bankPriv)]);
    $tok = json_decode($raw, true)['accessToken'] ?? '';
    check('bank memperoleh token dari aplikasi', $code === 200 && $tok !== '', "$code $raw");

    $pay = function ($amount, $reqId) use ($base, $tok, $va) {
        $path = '/v1.0/transfer-va/payment';
        $body = json_encode(['partnerServiceId' => $va['partner_service_id'], 'customerNo' => $va['customer_no'], 'virtualAccountNo' => $va['va_no'],
            'paymentRequestId' => $reqId, 'paidAmount' => ['value' => Snap_sign::amount($amount), 'currency' => 'IDR']]);
        $ts = Snap_sign::timestamp();
        list($code, $raw) = http('POST', $base . $path, null, $body, ['Content-Type: application/json', "Authorization: Bearer $tok", "X-TIMESTAMP: $ts",
            'X-SIGNATURE: ' . Snap_sign::symmetric('POST', $path, $tok, $body, $ts, 'secret-in-456'),
            'X-PARTNER-ID: BNI', 'X-EXTERNAL-ID: ' . Snap_sign::externalId(), 'CHANNEL-ID: 95231']);
        return [$code, json_decode($raw, true), $raw];
    };
    list($code, $r, $raw) = $pay(1750000, "BK$run");
    check('pembayaran diterima (2002500)', $code === 200 && ($r['responseCode'] ?? '') === '2002500', "$code $raw");

    $b = $h->query("SELECT paid_amount FROM booked_info WHERE bookedid=$bookedid")->fetch_row();
    check('booking: paid_amount = 1.750.000', (float) $b[0] === 1750000.0, json_encode($b));
    $gp = $h->query("SELECT invoice,paymenttype,paymentamount FROM tbl_guestpayments WHERE bookedid=$bookedid")->fetch_all(MYSQLI_ASSOC);
    check('tbl_guestpayments: 1 baris Virtual Account BNI', count($gp) === 1 && $gp[0]['paymenttype'] === 'Virtual Account BNI' && (float) $gp[0]['paymentamount'] === 1750000.0, json_encode($gp));
    $inv = $gp ? $h->real_escape_string($gp[0]['invoice']) : '';
    $acc = $h->query("SELECT COAID,Debit,Credit FROM acc_transaction WHERE VNo='$inv' AND Narration LIKE '%Invoice%$inv' ORDER BY ID")->fetch_all(MYSQLI_ASSOC);
    $coa = array_column($acc, 'COAID');
    $deb = array_sum(array_column($acc, 'Debit')); $cre = array_sum(array_column($acc, 'Credit'));
    check('jurnal 4 baris, seimbang, kas di akun BNI VA (102010305)', count($acc) === 4 && in_array('102010305', $coa) && abs($deb - $cre) < 0.01, json_encode($acc));
    $bal = $h->query("SELECT balance FROM customerinfo WHERE customerid=$cust")->fetch_row()[0];
    check('saldo uang muka tamu bertambah', (float) $bal === 1750000.0, $bal);
    check('booked_details mencatat metode VA', $h->query("SELECT payment_method FROM booked_details WHERE bookedid=$bookedid")->fetch_row()[0] === 'Virtual Account BNI');
    check('VA ditandai sudah diteruskan', (bool) $store->getVa($va['id'])['dispatched_at'], (string) $store->getVa($va['id'])['dispatch_error']);

    list($code, $r) = $pay(1750000, "BK$run");
    $n = $h->query("SELECT COUNT(*) FROM tbl_guestpayments WHERE bookedid=$bookedid")->fetch_row()[0];
    check('notifikasi berulang: sukses tanpa pencatatan ganda', $code === 200 && (int) $n === 1, "$code n=$n");

    list(, $body) = http('GET', "$base/hotel/va_status/$bn", $guest);
    check('status polling: paid', json_decode($body, true) === ['status' => 'paid'], $body);
    list($code, $body) = http('GET', "$base/hotel/va/$bn", $guest);
    check('halaman tamu: "Pembayaran diterima"', $code === 200 && strpos($body, 'Pembayaran diterima') !== false);

    echo "4. Nomor booking sama di hotel lain tidak tertukar\n";
    $svc = new Snap_service(['profiles' => [$HOTEL => $profile, 'hotel_lain' => $profile]], $store);
    $vaOther = $svc->issue('hotel_lain', 'booking', 'tenant_lain_uji', $bn, 'Tamu Lain', 500000, '+1 hour');
    check('VA hotel lain untuk nomor booking sama = VA berbeda', (int) $vaOther['id'] !== (int) $va['id'] && $vaOther['tenant_db'] === 'tenant_lain_uji');
    check('VA hotel pertama tetap lunas', $store->findByRef('booking', $bn, $HOTEL)['status'] === 'paid');
} finally {
    stop_mock($appProc);
    stop_mock($bankProc);
}

if ($GLOBALS['fail']) {
    echo "\n--- log aplikasi uji (ekor) ---\n" . implode("\n", array_slice(file("$tmp/app.log", FILE_IGNORE_NEW_LINES) ?: [], -15)) . "\n";
}
test_cleanup($tmp);
test_done();
