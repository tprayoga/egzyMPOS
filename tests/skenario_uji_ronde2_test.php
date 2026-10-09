<?php
/**
 * Regresi catatan penguji ronde 2 (Skenario_Uji_EGZY perbaikan.xlsx), CLI:
 *   php tests/skenario_uji_ronde2_test.php
 * Instance aplikasi uji (port 8801) pada DB hotel uji `snap_test_hotel` (dibuat tests/snapbi/booking_va_test.php).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require __DIR__ . '/snapbi/_helpers.php';
require $root . '/application/helpers/egzy_auth_helper.php';
$c = require $root . '/application/config/pbjt_db.php';
$HOTEL = 'snap_test_hotel';
$PORT = 8801;
$run = getmypid();
date_default_timezone_set('Asia/Jakarta');

$db = @new mysqli($c['host'], $c['user'], $c['pass'], $HOTEL);
if ($db->connect_errno || !$db->query("SELECT 1 FROM user LIMIT 1")) {
    fwrite(STDERR, "DB $HOTEL belum ada. Jalankan dulu: php tests/snapbi/booking_va_test.php\n");
    exit(1);
}
$db->query("SET SESSION sql_mode=''");
$mig = function ($v) use ($db, $root) {
    $db->multi_query(file_get_contents(glob("$root/application/migrations_pbjt/{$v}_*.sql")[0]));
    while ($db->more_results() && $db->next_result()) {}
    return $db->error;
};
foreach (['004', '005', '006', '009', '010', '011', '012'] as $v) $mig($v);

echo "1. Migrasi\n";
check('012: tujuh booking type bawaan tersedia', (int) $db->query("SELECT COUNT(*) FROM bookingtype WHERE booktypetitle IN
    ('Walk-in','Telepon / WhatsApp','Website Hotel','OTA (Traveloka, Tiket.com, dll.)','Korporat','Travel Agent','Grup / Event')")->fetch_row()[0] === 7);
check('011: tabel parkir huruf kecil dapat dipakai', (bool) $db->query("SELECT COUNT(*) FROM tbl_parkingsetting") && (bool) $db->query("SELECT COUNT(*) FROM tbl_bookparking"));

$ctl = test_db($root);
foreach (['tenants', 'directory'] as $t) $ctl->query("CREATE TABLE IF NOT EXISTS $t LIKE `{$c['control_db']}`.$t");
$ctl->query("REPLACE INTO tenants (wp_id,nama,db_name,jenis_usaha,kecamatan,status,dibuat) VALUES ('TSTHTL','Hotel Uji','$HOTEL','hotel','Soreang',1,NOW())");
$admin = "r2$run@uji.test";
$db->query("INSERT INTO user (firstname,lastname,email,password,status,usertype,is_admin) VALUES ('Adm','Uji','$admin','" . egzy_password_hash('UjiRonde2x') . "',1,1,1)") or die($db->error);
$ctl->query("REPLACE INTO directory (email,wp_id,db_name,is_bapenda) VALUES ('$admin','TSTHTL','$HOTEL',0)");
// tahun buku dikosongkan: harus dibuat otomatis
$fyBackup = $db->query("SELECT * FROM tbl_financialyear")->fetch_all(MYSQLI_ASSOC);
$db->query("DELETE FROM tbl_financialyear");
// waiter terjadwal sekarang tapi belum absen (is_complete=0) + shift malam lintas tengah malam
$h = (int) date('H');
$db->query("INSERT INTO employee_history (employee_id,pos_id,first_name,last_name,email) VALUES ('W1$run',9,'WPagi$run','Uji','w1$run@x.test'),('W2$run',9,'WMalam$run','Uji','w2$run@x.test')");
$d = date('Y-m-d'); $y = date('Y-m-d', strtotime('-1 day'));
$db->query("INSERT INTO tbl_emproster_assign (roster_id,emp_id,emp_startroster_date,emp_endroster_date,emp_startroster_time,emp_endroster_time,is_complete) VALUES
    ('R$run','W1$run','$d','$d',' " . sprintf('%02d', max(0, $h - 1)) . ":00',' " . sprintf('%02d', min(23, $h + 1)) . ":59',0),
    ('R$run','W2$run','$y','$y',' 22:00',' " . sprintf('%02d', min(23, $h + 1)) . ":00',0)") or die($db->error);
// produk: beli 50, rusak 2 lewat aplikasi
$db->query("INSERT INTO products (id,product_name,category_id,uom_id,stock,is_active) VALUES (99$run,'BerasUji$run',4,1,50,1)");
$db->query("INSERT INTO tbl_reuseableproduct (product_id, ready) VALUES (99$run, 50)");
$db->query("INSERT INTO purchaseitem (purID,invoiceid,suplierID,total_price,details,purchasedate,savedby,status) VALUES (99$run,'INV$run',1,600000,'',CURDATE(),1,0)");
$db->query("INSERT INTO purchase_details (purchaseid,proid,quantity,unitname,price,totalprice,purchaseby,purchasedate) VALUES (99$run,99$run,50,'kg',12000,600000,1,CURDATE())");

$tmp = test_tmpdir();
$app = proc_open("exec env PBJT_CONTROL_DB=snap_test php -S 127.0.0.1:$PORT " . escapeshellarg("$root/server_router.php"),
    [0 => ['pipe', 'r'], 1 => ['file', "$tmp/app.log", 'a'], 2 => ['file', "$tmp/app.log", 'a']], $pp, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $PORT); $i++) usleep(100000);
$jar = "$tmp/j"; touch($jar);
$req = function ($method, $path, $post = null, &$hdr = null) use ($PORT, $jar) {
    $ch = curl_init("http://127.0.0.1:$PORT$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true]);
    if ($post !== null) {
        $post['csrf_test_name'] = preg_match('/\tcsrf_cookie_name\t(\S+)/', (string) file_get_contents($jar), $m) ? $m[1] : '';
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $hdr = substr($raw, 0, $hs);
    return [$code, substr($raw, $hs)];
};
try {
    echo "2. HTTP\n";
    $req('GET', '/login');
    $sid = preg_match('/\tci_session\t(\S+)/', file_get_contents($jar), $m) ? $m[1] : '';
    $cap = preg_match('/captcha\|s:\d+:"([^"]*)"/', (string) @file_get_contents("$root/application/cache/temp/ci_session$sid"), $m) ? $m[1] : '';
    $hdr = null;
    list($code) = $req('POST', '/login', ['email' => $admin, 'password' => 'UjiRonde2x', 'captcha' => $cap], $hdr);
    check('login admin tenant uji', $code === 302 && preg_match('/Location: \S*dashboard/i', $hdr));

    list(, $body) = $req('GET', '/dashboard/home');
    check('aset diberi versi waktu ubah (cache Cloudflare/browser tidak basi)', (bool) preg_match('#pro-theme\.css\?v=\d{9,}#', $body) && (bool) preg_match('#global\.js\?v=\d{9,}#', $body));
    check('dashboard: total amount dalam Rupiah (bukan "240.3m")', (bool) preg_match('#font-weight-bold">Rp [\d.,]+( (rb|jt|M|T))?<#', $body));
    $fy = $db->query("SELECT COUNT(*) FROM tbl_financialyear WHERE start_date<=CURDATE() AND end_date>=CURDATE() AND is_active='2'")->fetch_row()[0];
    check('tahun buku kosong dibuat otomatis saat halaman dibuka (TC-020)', (int) $fy === 1, "$fy");

    list(, $body) = $req('GET', '/ordermanage/order/pos_invoice');
    check('POS: waiter terjadwal (belum absen) muncul (TC-029)', strpos($body, "WPagi$run") !== false);
    check('POS: waiter shift malam lintas tengah malam muncul', strpos($body, "WMalam$run") !== false);

    $req('POST', '/units/products/descreate', ['product_id' => "99$run", 'quantity' => 2, 'queue' => 'ready', 'comments' => 'basah', 'charge' => 0]);
    list(, $body) = $req('GET', '/reports/stock-report');
    $i = strpos($body, "BerasUji$run"); $row = $i === false ? '' : substr($body, $i, strpos($body, '</tr>', $i) - $i);
    $cells = array_map(function ($x) { return trim(strip_tags($x)); }, explode('</td>', $row));
    check('laporan stok: dibeli 50, rusak 2, stok 48 (TC-036)', isset($cells[5]) && strpos($cells[1], '50') === 0 && strpos($cells[3], '2') === 0 && strpos($cells[5], '48') === 0, json_encode($cells));

    list($code) = $req('GET', '/car_parking/car_parking/settingList');
    check('Car Parking setting list terbuka (TC-041)', $code === 200, "HTTP $code");
    list(, $body) = $req('POST', '/car_parking/car_parking/getZone', ['vehicle_type' => 0]);
    check('Car Parking getZone membalas JSON (query tabel huruf kecil jalan)', is_array(json_decode(substr($body, strpos($body, '[')), true)), substr($body, 0, 80));

    list(, $form) = $req('GET', '/hrm/add-employee');
    check('form pegawai tanpa Ethnic Group & EEO Class (label NIK tetap ada)', strpos($form, 'name="ssn"') !== false
        && stripos($form, 'EEO Class') === false && stripos($form, 'Ethnic Group') === false);
    list(, $body) = $req('GET', '/house_keeping/item_cost');
    check('Laundry Item cost: petunjuk asal Task Name (TC-039)', strpos($body, 'Type = Laundry') !== false);
} finally {
    proc_terminate($app); proc_close($app);
    $db->query("DELETE FROM tbl_emproster_assign WHERE roster_id='R$run'");
    $db->query("DELETE FROM employee_history WHERE employee_id IN ('W1$run','W2$run')");
    foreach (["DELETE FROM purchase_details WHERE purchaseid=99$run", "DELETE FROM purchaseitem WHERE purID=99$run", "DELETE FROM tbl_destroyedproduct WHERE product_id=99$run",
              "DELETE FROM tbl_reuseableproduct WHERE product_id=99$run", "DELETE FROM products WHERE id=99$run", "DELETE FROM user WHERE email='$admin'"] as $q) $db->query($q);
    $db->query("DELETE FROM tbl_financialyear");
    foreach ($fyBackup as $r) {
        $cols = implode(',', array_map(function ($k) { return "`$k`"; }, array_keys($r)));
        $vals = implode(',', array_map(function ($v) use ($db) { return $v === null ? 'NULL' : "'" . $db->real_escape_string($v) . "'"; }, $r));
        $db->query("INSERT INTO tbl_financialyear ($cols) VALUES ($vals)");
    }
    $ctl->query("DELETE FROM directory WHERE email='$admin'");
}
if ($GLOBALS['fail']) echo implode("\n", array_slice(file("$tmp/app.log", FILE_IGNORE_NEW_LINES) ?: [], -10)) . "\n";
test_cleanup($tmp);
test_done();
