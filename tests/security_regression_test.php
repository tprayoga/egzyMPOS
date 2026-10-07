<?php
/**
 * Regresi keamanan (CLI):  php tests/security_regression_test.php
 *
 * Menjalankan instance aplikasi uji (port 8795, DB hotel uji `snap_test_hotel` dari
 * tests/snapbi/booking_va_test.php) lalu memastikan lubang yang sudah ditutup tetap tertutup:
 *  1. Dump DB tanpa login (dashboard/autoupdate/download_backup)
 *  2. Unduh+ekstrak kode tanpa login (dashboard/autoupdate/updatenow) & addon module/theme
 *  3. Booking ditandai lunas lewat URL hotel/successful tanpa verifikasi gateway
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require __DIR__ . '/snapbi/_helpers.php';
$c = require $root . '/application/config/pbjt_db.php';
$HOTEL = 'snap_test_hotel';
$PORT = 8795;
$run = getmypid();

$h = @new mysqli($c['host'], $c['user'], $c['pass'], $HOTEL);
if ($h->connect_errno || !$h->query("SELECT 1 FROM booked_info LIMIT 1")) {
    fwrite(STDERR, "DB $HOTEL belum ada. Jalankan dulu: php tests/snapbi/booking_va_test.php\n");
    exit(1);
}
$ctl = test_db($root);
$ctl->query("CREATE TABLE IF NOT EXISTS tenants LIKE `{$c['control_db']}`.tenants");
$ctl->query("REPLACE INTO tenants (wp_id,nama,db_name,jenis_usaha,kecamatan,status,dibuat) VALUES ('TSTHTL','Hotel Uji','$HOTEL','hotel','Soreang',1,NOW())");

$h->query("INSERT INTO customerinfo (firstname,lastname,email,pass,balance) VALUES ('Sec','Uji','sec$run@uji.test','x',0)");
$cust = $h->insert_id;
$bn = 'S' . $run;
$h->query("INSERT INTO booked_info (booking_number,date_time,roomid,room_no,total_price,paid_amount,full_guest_name,checkindate,checkoutdate,cutomerid,bookingstatus)
           VALUES ('$bn',NOW(),'1','101',900000,0,'Sec Uji',NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY),$cust,0)") or die($h->error);
$bookedid = $h->insert_id;
$h->query("INSERT INTO booked_details (bookedid) VALUES ($bookedid)");

$tmp = test_tmpdir();
$app = proc_open("exec env PBJT_CONTROL_DB=snap_test php -S 127.0.0.1:$PORT " . escapeshellarg("$root/server_router.php"),
    [0 => ['pipe', 'r'], 1 => ['file', "$tmp/app.log", 'a'], 2 => ['file', "$tmp/app.log", 'a']], $p, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $PORT); $i++) usleep(100000);

$jar = "$tmp/jar";
file_put_contents($jar, "127.0.0.1\tFALSE\t/\tFALSE\t0\ttnt\t$HOTEL\n");
function req($method, $path, $post = null)
{
    global $PORT, $jar;
    $ch = curl_init("http://127.0.0.1:$PORT$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string) $body];
}
$backupDir = "$root/assets/data/backup";
$countBackups = function () use ($backupDir) { return count(glob("$backupDir/*.sql")); };

try {
    echo "1. Dump database tanpa login\n";
    $before = $countBackups();
    list($code, $body) = req('GET', '/dashboard/autoupdate/download_backup');
    check('download_backup -> 404, tanpa isi SQL', $code === 404 && stripos($body, 'CREATE TABLE') === false, "HTTP $code");
    check('tidak ada file dump baru di assets/data/backup', $countBackups() === $before);

    echo "2. Pasang kode dari luar\n";
    list($code) = req('POST', '/dashboard/autoupdate/updatenow', ['update_url' => 'http://127.0.0.1:1/x.zip', 'purchase_key' => 'x']);
    check('autoupdate/updatenow diblokir (CSRF 403 / 404)', $code === 403 || $code === 404, "HTTP $code");
    check('autoupdate -> 404', req('GET', '/autoupdate')[0] === 404);
    foreach (['/module', '/addon/module/upload', '/addon/module/download_module', '/addon/theme', '/addon/theme/upload_new_theme', '/addon/moduleruningbackup'] as $u) {
        list($code) = req('GET', $u);
        check("$u -> 404", $code === 404, "HTTP $code");
    }
    list($code) = req('GET', '/dashboard/module/upload');
    check('dashboard/module/upload tanpa login -> dialihkan', $code === 302, "HTTP $code");
    check('folder temp autoupdate tidak tercipta', !is_dir("$root/temp/v"));

    echo "3. Lunas palsu lewat hotel/successful\n";
    foreach ([3 => 'PayPal', 6 => 'Bank Payment', 2 => 'id 2'] as $pm => $label) {
        req('GET', "/hotel/successful/$bn/$pm");
    }
    list($code) = req('POST', "/hotel/successful/$bn/5", ['tran_id' => $bn, 'val_id' => 'PALSU', 'amount' => '900000', 'verify_sign' => 'x', 'verify_key' => 'tran_id']);
    $paid = (float) $h->query("SELECT paid_amount FROM booked_info WHERE bookedid=$bookedid")->fetch_row()[0];
    $rows = (int) $h->query("SELECT COUNT(*) FROM tbl_guestpayments WHERE bookedid=$bookedid")->fetch_row()[0];
    check('PayPal/transfer/lainnya via URL tidak mencatat pembayaran', $paid === 0.0 && $rows === 0, "paid=$paid rows=$rows");
    check('SSLCommerz dengan val_id palsu tidak dicatat', $paid === 0.0);
    list($code) = req('GET', '/hotel/successful/TIDAKADA/3');
    check('nomor booking tak dikenal -> dialihkan, bukan error', $code === 302, "HTTP $code");
} finally {
    stop_mock($app);
}
if ($GLOBALS['fail']) echo implode("\n", array_slice(file("$tmp/app.log", FILE_IGNORE_NEW_LINES) ?: [], -10)) . "\n";
test_cleanup($tmp);
test_done();
