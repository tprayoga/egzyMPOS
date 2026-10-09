<?php
/**
 * Regresi perbaikan dari Skenario_Uji_EGZY (CLI):  php tests/skenario_uji_test.php
 *
 *  1. Migrasi 005–008 pada DB sementara (collation seragam, tahun buku, teks UI, customer type ganda).
 *  2. HTTP pada DB hotel uji `snap_test_hotel` (dibuat oleh tests/snapbi/booking_va_test.php):
 *     form customer, tombol payment method, QR kamar lokal, filter & injeksi datatable booking,
 *     Records House Keeping, dashboard restoran.
 *  3. Instance oversight (Bapenda): modul operasional ditolak server-side.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require __DIR__ . '/snapbi/_helpers.php';
require $root . '/application/helpers/egzy_auth_helper.php';
$c = require $root . '/application/config/pbjt_db.php';
$MIG = $root . '/application/migrations_pbjt';
$HOTEL = 'snap_test_hotel';
$PORT = 8797;      // instance tenant
$PORT_OV = 8798;   // instance oversight (DB oversight = DB hotel uji)
$run = getmypid();

function run_sql(mysqli $m, $sql)
{
    if (!$m->multi_query($sql)) return $m->error;
    while (true) {
        if ($r = $m->store_result()) $r->free();
        if (!$m->more_results()) return '';
        if (!$m->next_result()) return $m->error;
    }
}
function mig(mysqli $m, $dir, $ver) { return run_sql($m, file_get_contents(glob("$dir/{$ver}_*.sql")[0])); }

// ---------------------------------------------------------------- 1. Migrasi pada DB sementara
echo "1. Migrasi 005-008\n";
$tdb = 'skenario_test_' . $run;
$m = new mysqli($c['host'], $c['user'], $c['pass']);
$m->query("CREATE DATABASE `$tdb` CHARACTER SET latin1");
$m->select_db($tdb);
run_sql($m, "
CREATE TABLE a (id INT PRIMARY KEY, k VARCHAR(20)) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci;
CREATE TABLE b (id INT PRIMARY KEY, k VARCHAR(20)) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci;
CREATE TABLE l (id INT PRIMARY KEY, k VARCHAR(20)) CHARACTER SET latin1;
INSERT INTO a VALUES (1,'X1'); INSERT INTO b VALUES (1,'X1'); INSERT INTO l VALUES (1,'X1');
CREATE TABLE tbl_financialyear (fiyear_id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(50) NOT NULL, start_date DATE, end_date DATE,
  date_time DATETIME, is_active VARCHAR(3), create_by VARCHAR(3));
CREATE TABLE language (id INT AUTO_INCREMENT PRIMARY KEY, phrase VARCHAR(100), english VARCHAR(255));
INSERT INTO language (phrase, english) VALUES ('ssn','SSN'),('booking_source','Booking Soruce');
CREATE TABLE tbl_category (category_id INT PRIMARY KEY, categoryname VARCHAR(100), status VARCHAR(2));
INSERT INTO tbl_category VALUES (501,'Houekeeping products','1');
CREATE TABLE tbl_soundsetting (soundid INT PRIMARY KEY, nofitysound VARCHAR(100));
INSERT INTO tbl_soundsetting VALUES (1,'assets/2021-03-21/b1.mp3');
CREATE TABLE customer_type (customer_type_id INT AUTO_INCREMENT PRIMARY KEY, customer_type VARCHAR(50), ordering INT);
INSERT INTO customer_type (customer_type, ordering) VALUES ('Walk In Customer',1),('Take Way',0),('Walk In Customer',1),('Take Way',0);
CREATE TABLE customer_order (order_id INT AUTO_INCREMENT PRIMARY KEY, cutomertype INT);
INSERT INTO customer_order (cutomertype) VALUES (1),(3),(3),(4);
");
check('sebelum 005: JOIN beda collation gagal', !$m->query("SELECT 1 FROM a JOIN b ON a.k=b.k"));
foreach (['005', '005', '006', '006', '007', '008', '008'] as $v) { $e = mig($m, $MIG, $v); check("migrasi $v jalan (idempoten)", $e === '', $e); }
check('005: JOIN antar tabel kini jalan', (bool) $m->query("SELECT 1 FROM a JOIN b ON a.k=b.k JOIN l ON l.k=a.k"));
$n = $m->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$tdb' AND collation_name IS NOT NULL AND collation_name<>'utf8mb4_general_ci'")->fetch_row()[0];
check('005: semua kolom utf8mb4_general_ci', (int) $n === 0, "$n kolom berbeda");
check('005: data utuh', $m->query("SELECT k FROM l WHERE id=1")->fetch_row()[0] === 'X1');
$fy = $m->query("SELECT COUNT(*), MIN(start_date), MIN(is_active) FROM tbl_financialyear")->fetch_row();
check('006: satu tahun buku kalender berjalan, aktif', $fy[0] == 1 && $fy[1] === date('Y') . '-01-01' && $fy[2] === '2', implode(',', $fy));
check('007: SSN -> NIK', $m->query("SELECT english FROM language WHERE phrase='ssn'")->fetch_row()[0] === 'NIK');
check('007: nama kategori laundry', $m->query("SELECT categoryname FROM tbl_category WHERE category_id=501")->fetch_row()[0] === 'Housekeeping products');
check('007: bunyi notifikasi ke file yang ada', is_file($root . '/' . $m->query("SELECT nofitysound FROM tbl_soundsetting")->fetch_row()[0]));
check('008: tiap customer type sekali', (int) $m->query("SELECT COUNT(*) FROM customer_type")->fetch_row()[0] === 2);
check('008: Take Way -> Take Away', (int) $m->query("SELECT COUNT(*) FROM customer_type WHERE customer_type='Take Away'")->fetch_row()[0] === 1);
$orphan = $m->query("SELECT COUNT(*) FROM customer_order o LEFT JOIN customer_type t ON t.customer_type_id=o.cutomertype WHERE t.customer_type_id IS NULL")->fetch_row()[0];
check('008: order tetap merujuk tipe yang ada', (int) $orphan === 0, "$orphan order yatim");
$m->query("DROP DATABASE `$tdb`");

// ---------------------------------------------------------------- 2-3. HTTP
$h = @new mysqli($c['host'], $c['user'], $c['pass'], $HOTEL);
if ($h->connect_errno || !$h->query("SELECT 1 FROM user LIMIT 1")) {
    fwrite(STDERR, "DB $HOTEL belum ada. Jalankan dulu: php tests/snapbi/booking_va_test.php\n");
    exit(1);
}
foreach (['004', '005', '006', '007', '008'] as $v) mig($h, $MIG, $v);

$ctl = test_db($root);
foreach (['tenants', 'directory'] as $t) $ctl->query("CREATE TABLE IF NOT EXISTS $t LIKE `{$c['control_db']}`.$t");
$ctl->query("REPLACE INTO tenants (wp_id,nama,db_name,jenis_usaha,kecamatan,status,dibuat) VALUES ('TSTHTL','Hotel Uji','$HOTEL','hotel','Soreang',1,NOW())");
$admin = "skn$run@uji.test";
$h->query("INSERT INTO user (firstname,lastname,email,password,status,usertype,is_admin) VALUES ('Adm','Uji','$admin','" . egzy_password_hash('UjiSkenario1') . "',1,1,1)") or die($h->error);
$h->query("INSERT INTO customerinfo (firstname,lastname,email,cust_phone,balance) VALUES ('TanpaHP','Uji','nohp$run@uji.test',NULL,0)");
$ctl->query("REPLACE INTO directory (email,wp_id,db_name,is_bapenda) VALUES ('$admin','TSTHTL','$HOTEL',0)");

$tmp = test_tmpdir();
$start = function ($port, $env) use ($root, $tmp) {
    $p = proc_open("exec env $env php -S 127.0.0.1:$port " . escapeshellarg("$root/server_router.php"),
        [0 => ['pipe', 'r'], 1 => ['file', "$tmp/app$port.log", 'a'], 2 => ['file', "$tmp/app$port.log", 'a']], $pp, $root);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    return $p;
};
$app = $start($PORT, 'PBJT_CONTROL_DB=snap_test');
$appOv = $start($PORT_OV, "PBJT_CONTROL_DB=snap_test PBJT_OVERSIGHT_DB=$HOTEL");

function req($port, $jar, $method, $path, $post = null, &$headers = null)
{
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $post !== null ? ['X-Requested-With: XMLHttpRequest'] : []]);
    if ($post !== null) {
        $post['csrf_test_name'] = preg_match('/\tcsrf_cookie_name\t(\S+)/', (string) @file_get_contents($jar), $mm) ? $mm[1] : '';
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $headers = substr($raw, 0, $hs);
    return [$code, substr($raw, $hs)];
}
function login($port, $tmp, $email, $pass)
{
    global $root;
    $j = "$tmp/j$port" . bin2hex(random_bytes(3));
    touch($j);
    req($port, $j, 'GET', '/login');
    $sid = preg_match('/\tci_session\t(\S+)/', file_get_contents($j), $mm) ? $mm[1] : '';
    $cap = preg_match('/captcha\|s:\d+:"([^"]*)"/', (string) @file_get_contents("$root/application/cache/temp/ci_session$sid"), $mm) ? $mm[1] : '';
    $hdr = null;
    list($code) = req($port, $j, 'POST', '/login', ['email' => $email, 'password' => $pass, 'captcha' => $cap], $hdr);
    return [$code === 302 && preg_match('/Location: \S*dashboard/i', $hdr), $j];
}

try {
    echo "2. Tenant (instance hotel uji)\n";
    list($ok, $j) = login($PORT, $tmp, $admin, 'UjiSkenario1');
    check('login admin tenant uji', $ok);

    list($code, $body) = req($PORT, $j, 'GET', '/customer/customer_info/create');
    check('TC-019: form tambah customer tampil walau ada customer tanpa HP', $code === 200 && strpos($body, 'name="firstname"') !== false, "HTTP $code");
    list($code, $body) = req($PORT, $j, 'GET', '/payment_setting/payment-method-list');
    check('TC-001: tombol tambah payment method ada', strpos($body, 'data-target="#add0"') !== false, "HTTP $code");
    list($code, $body) = req($PORT, $j, 'GET', '/house_keeping/room-qrcode');
    check('TC-018: QR kamar dibuat lokal (bukan Google Chart)', $code === 200 && strpos($body, 'chart.apis.google.com') === false
        && (strpos($body, 'data:image/png;base64,') !== false || strpos($body, 'Room No') === false), "HTTP $code");
    list($code) = req($PORT, $j, 'GET', '/house_keeping/all-records');
    check('TC-038: House Keeping > Records tanpa error', $code === 200, "HTTP $code");
    list($code, $body) = req($PORT, $j, 'GET', '/dashboard/home');
    check('findate (tahun buku) terisi di layout', (bool) preg_match('/id="findate" value="\d{4}-\d{2}-\d{2}"/', $body));

    $dt = function ($path, $search, $status = null) use ($PORT, $j) {
        $p = ['draw' => 1, 'start' => 0, 'length' => 5, 'order' => [['column' => 1, 'dir' => 'desc']], 'search' => ['value' => $search]];
        if ($status !== null) $p['status'] = $status;
        list($code, $body) = req($PORT, $j, 'POST', $path, $p);
        $d = json_decode($body, true);
        return [$code, is_array($d) ? (int) $d['recordsTotal'] : -1];
    };
    list($c0, $nPending) = $dt('/room_reservation/room_reservation/bookingdatatable', '', '0');
    list($c1, $nAll) = $dt('/room_reservation/room_reservation/bookingdatatable', '', 'all');
    check('RK-11: filter status booking (semua >= pending)', $c0 === 200 && $c1 === 200 && $nAll >= $nPending && $nAll >= 0, "pending=$nPending semua=$nAll");
    list($ci, $nInj) = $dt('/room_reservation/room_reservation/bookingdatatable', "x' OR 1=1-- ", 'all');
    check('booking list: injeksi lewat pencarian tidak berlaku', $ci === 200 && $nInj === 0, "HTTP $ci n=$nInj");
    list($ci, $nInj) = $dt('/room_reservation/room_reservation/checkindatatable', "x' OR 1=1-- ");
    check('check-in list: pencarian jalan & injeksi tak berlaku', $ci === 200 && $nInj === 0, "HTTP $ci n=$nInj");

    $ctl->query("UPDATE tenants SET jenis_usaha='resto' WHERE wp_id='TSTHTL'");
    list($code, $body) = req($PORT, $j, 'GET', '/dashboard/home');
    check('RK-10: tenant resto melihat dashboard restoran', $code === 200 && strpos($body, 'Omzet order selesai') !== false, "HTTP $code");
    $ctl->query("UPDATE tenants SET jenis_usaha='hotel' WHERE wp_id='TSTHTL'");
    list(, $body) = req($PORT, $j, 'GET', '/dashboard/home');
    check('tenant hotel tetap dashboard hotel', strpos($body, 'Omzet order selesai') === false);

    echo "3. Instance oversight (Bapenda)\n";
    $ctl->query("DELETE FROM directory WHERE email='$admin'"); // tanpa direktori -> DB default = oversight
    list($ok, $jo) = login($PORT_OV, $tmp, $admin, 'UjiSkenario1');
    check('login admin di instance oversight', $ok);
    foreach (['/room_reservation/booking-list', '/ordermanage/order/pos_invoice', '/hrm/Employees/viewEmhistory'] as $u) {
        $hdr = null;
        list($code) = req($PORT_OV, $jo, 'GET', $u, null, $hdr);
        check("Temuan 1: $u ditolak -> /pbjt", $code === 302 && preg_match('#Location: \S*/pbjt#', $hdr), "HTTP $code");
    }
    list($code) = req($PORT_OV, $jo, 'POST', '/room_reservation/room_reservation/bookingdatatable', ['draw' => 1, 'start' => 0, 'length' => 5]);
    check('Temuan 1: AJAX data operasional ditolak 403', $code === 403, "HTTP $code");
    $hdr = null;
    list($code) = req($PORT_OV, $jo, 'GET', '/dashboard/home', null, $hdr);
    check('beranda Bapenda = Pengawasan PBJT', $code === 302 && preg_match('#Location: \S*/pbjt#', $hdr), "HTTP $code");
} finally {
    foreach ([$app, $appOv] as $p) { proc_terminate($p); proc_close($p); }
    $h->query("DELETE FROM user WHERE email='$admin'");
    $h->query("DELETE FROM customerinfo WHERE email='nohp$run@uji.test'");
    $ctl->query("DELETE FROM directory WHERE email='$admin'");
}
if ($GLOBALS['fail']) foreach ([$PORT, $PORT_OV] as $p) echo implode("\n", array_slice(file("$tmp/app$p.log", FILE_IGNORE_NEW_LINES) ?: [], -10)) . "\n";
test_cleanup($tmp);
test_done();
