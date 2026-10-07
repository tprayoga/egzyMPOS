<?php
/**
 * Regresi autentikasi (CLI):  php tests/auth_regression_test.php
 *
 * Instance aplikasi uji (port 8796) pada DB hotel uji `snap_test_hotel`
 * (dibuat oleh tests/snapbi/booking_va_test.php). Memastikan:
 *  1. Login admin & tamu menerima hash md5 lama lalu meng-upgrade ke bcrypt.
 *  2. Reset password tidak bisa dipakai mengambil alih akun (wajib OTP di sesi yang sama,
 *     batas percobaan, token sekali pakai, kebijakan panjang password).
 *  3. API mobile tanpa autentikasi nonaktif; cookie CSRF HttpOnly.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require __DIR__ . '/snapbi/_helpers.php';
require $root . '/application/helpers/egzy_auth_helper.php';
$c = require $root . '/application/config/pbjt_db.php';
$HOTEL = 'snap_test_hotel';
$PORT = 8796;
$run = getmypid();

$h = @new mysqli($c['host'], $c['user'], $c['pass'], $HOTEL);
if ($h->connect_errno || !$h->query("SELECT 1 FROM user LIMIT 1")) {
    fwrite(STDERR, "DB $HOTEL belum ada. Jalankan dulu: php tests/snapbi/booking_va_test.php\n");
    exit(1);
}
$h->multi_query(file_get_contents($root . '/application/migrations_pbjt/004_password_hash_bcrypt.sql'));
while ($h->more_results() && $h->next_result()) {}

$ctl = test_db($root);
foreach (['tenants', 'directory'] as $t) $ctl->query("CREATE TABLE IF NOT EXISTS $t LIKE `{$c['control_db']}`.$t");
$ctl->query("REPLACE INTO tenants (wp_id,nama,db_name,jenis_usaha,kecamatan,status,dibuat) VALUES ('TSTHTL','Hotel Uji','$HOTEL','hotel','Soreang',1,NOW())");

$admin = "adm$run@uji.test";
$guest = "tamu$run@uji.test";
$h->query("INSERT INTO user (firstname,lastname,email,password,status,usertype,is_admin) VALUES ('Adm','Uji','$admin','" . md5('LamaSekali1') . "',1,1,1)") or die($h->error);
$h->query("INSERT INTO customerinfo (firstname,lastname,email,pass,balance) VALUES ('Tamu','Uji','$guest','" . md5('TamuLama12') . "',0)") or die($h->error);
$ctl->query("REPLACE INTO directory (email,wp_id,db_name,is_bapenda) VALUES ('$admin','TSTHTL','$HOTEL',0)");
$pw = function ($table, $col, $email) use ($h) { return $h->query("SELECT $col FROM $table WHERE email='$email'")->fetch_row()[0]; };

$tmp = test_tmpdir();
$app = proc_open("exec env PBJT_CONTROL_DB=snap_test php -S 127.0.0.1:$PORT " . escapeshellarg("$root/server_router.php"),
    [0 => ['pipe', 'r'], 1 => ['file', "$tmp/app.log", 'a'], 2 => ['file', "$tmp/app.log", 'a']], $p, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $PORT); $i++) usleep(100000);

function jar($tmp, $name, $tnt = null)
{
    $j = "$tmp/$name.jar";
    file_put_contents($j, $tnt ? "127.0.0.1\tFALSE\t/\tFALSE\t0\ttnt\t$tnt\n" : '');
    return $j;
}
function req($jar, $method, $path, $post = null, &$headers = null)
{
    global $PORT;
    $ch = curl_init("http://127.0.0.1:$PORT$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true]);
    if ($post !== null) {
        $post['csrf_test_name'] = cookie($jar, 'csrf_cookie_name');
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $headers = substr((string) $raw, 0, $hs);
    return [$code, substr((string) $raw, $hs)];
}
function cookie($jar, $name)
{
    return preg_match('/\t' . preg_quote($name, '/') . '\t(\S+)/', (string) @file_get_contents($jar), $m) ? $m[1] : '';
}
/** Captcha login admin dibaca dari file sesi milik browser uji ini sendiri. */
function captcha($jar)
{
    global $root;
    $f = "$root/application/cache/temp/ci_session" . cookie($jar, 'ci_session');
    return preg_match('/captcha\|s:\d+:"([^"]*)"/', (string) @file_get_contents($f), $m) ? $m[1] : '';
}
function admin_login($tmp, $email, $password)
{
    $j = jar($tmp, 'adm' . bin2hex(random_bytes(3)));
    req($j, 'GET', '/login');
    list($code, , ) = [0, 0, 0];
    $hdr = null;
    list($code) = req($j, 'POST', '/login', ['email' => $email, 'password' => $password, 'captcha' => captcha($j)], $hdr);
    $ok = $code === 302 && preg_match('/Location: \S*dashboard/i', $hdr);
    return [$ok, $j];
}

try {
    echo "1. Login & upgrade hash\n";
    list($ok) = admin_login($tmp, $admin, 'salah-password');
    check('admin: password salah ditolak', !$ok);
    list($ok) = admin_login($tmp, $admin, 'LamaSekali1');
    check('admin: hash md5 lama tetap bisa login', $ok);
    $stored = $pw('user', 'password', $admin);
    check('admin: hash di-upgrade ke bcrypt', strpos($stored, '$2y$') === 0 && egzy_password_check('LamaSekali1', $stored), substr($stored, 0, 7));
    list($ok) = admin_login($tmp, $admin, 'LamaSekali1');
    check('admin: login ulang dengan hash bcrypt', $ok);

    $g = jar($tmp, 'guest', $HOTEL);
    req($g, 'GET', '/hotel');
    list($code) = req($g, 'POST', '/hotel/loginsubmit', ['email' => $guest, 'password' => 'salah']);
    check('tamu: password salah ditolak', $code === 200);
    list($code) = req($g, 'POST', '/hotel/loginsubmit', ['email' => $guest, 'password' => 'TamuLama12']);
    check('tamu: hash md5 lama bisa login', $code === 302, "HTTP $code");
    check('tamu: hash di-upgrade ke bcrypt', strpos($pw('customerinfo', 'pass', $guest), '$2y$') === 0);

    echo "2. Reset password admin\n";
    $atk = jar($tmp, 'attacker', $HOTEL);
    req($atk, 'GET', '/login');
    list(, $body) = req($atk, 'POST', '/dashboard/auth/new_password', ['hemail' => $admin, 'forgot' => 'DiambilAlih99']);
    check('TANPA OTP: ganti password ditolak', strpos($body, '"status":"false"') !== false, $body);
    check('TANPA OTP: password tidak berubah', egzy_password_check('LamaSekali1', $pw('user', 'password', $admin)));

    $r = jar($tmp, 'reset');
    req($r, 'GET', '/dashboard/auth/forgot_password');
    req($r, 'POST', '/dashboard/auth/forgot_check', ['forgot' => $admin]);
    $token = $pw('user', 'password_reset_token', $admin);
    check('OTP 8 karakter dari alfabet aman', (bool) preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $token), $token);
    list(, $body) = req($atk, 'POST', '/dashboard/auth/check_code', ['hemail' => $admin, 'forgot' => $token]);
    check('OTP benar dari SESI LAIN ditolak', strpos($body, '"status":"false"') !== false, $body);
    for ($i = 0; $i < 5; $i++) req($r, 'POST', '/dashboard/auth/check_code', ['hemail' => $admin, 'forgot' => 'ZZZZZZZZ']);
    list(, $body) = req($r, 'POST', '/dashboard/auth/check_code', ['hemail' => $admin, 'forgot' => $token]);
    check('setelah 5 percobaan salah, OTP benar pun ditolak', strpos($body, '"status":"false"') !== false, $body);

    req($r, 'POST', '/dashboard/auth/forgot_check', ['forgot' => $admin]);
    $token2 = $pw('user', 'password_reset_token', $admin);
    check('permintaan baru menerbitkan OTP baru', $token2 !== $token);
    list(, $body) = req($r, 'POST', '/dashboard/auth/check_code', ['hemail' => $admin, 'forgot' => strtolower($token2)]);
    check('OTP benar di sesi yang sama diterima', strpos($body, '"status":"true"') !== false, $body);
    list(, $body) = req($r, 'POST', '/dashboard/auth/new_password', ['hemail' => $admin, 'forgot' => 'pendek']);
    check('password < 8 karakter ditolak', strpos($body, 'minimal 8') !== false, $body);
    list(, $body) = req($r, 'POST', '/dashboard/auth/new_password', ['hemail' => $admin, 'forgot' => 'PasswordBaru#2026']);
    check('password baru disimpan (bcrypt)', strpos($body, '"status":"true"') !== false && egzy_password_check('PasswordBaru#2026', $pw('user', 'password', $admin)), $body);
    check('token reset dikosongkan', $pw('user', 'password_reset_token', $admin) === '');
    list(, $body) = req($r, 'POST', '/dashboard/auth/new_password', ['hemail' => $admin, 'forgot' => 'LagiLagi#2026']);
    check('reset tidak bisa dipakai dua kali', strpos($body, '"status":"false"') !== false, $body);
    list($ok) = admin_login($tmp, $admin, 'PasswordBaru#2026');
    check('login dengan password baru', $ok);

    echo "3. Reset password tamu\n";
    $ga = jar($tmp, 'gatk', $HOTEL);
    req($ga, 'GET', '/hotel');
    list(, $body) = req($ga, 'POST', '/hotel/new_password', ['hemail' => $guest, 'forgot' => 'DiambilAlih99']);
    check('tamu TANPA OTP: ditolak & password utuh', strpos($body, '"status":"false"') !== false && egzy_password_check('TamuLama12', $pw('customerinfo', 'pass', $guest)), $body);

    echo "4. Permukaan lain\n";
    foreach (['/app/profile_update', '/app/sign_in', '/api_handler_v2/login'] as $u) {
        list($code) = req(jar($tmp, 'api'), 'GET', $u);
        check("$u -> 404", $code === 404, "HTTP $code");
    }
    $hdr = null;
    req(jar($tmp, 'ck'), 'GET', '/login', null, $hdr);
    check('cookie CSRF ber-HttpOnly', (bool) preg_match('/Set-Cookie: csrf_cookie_name=[^\r\n]*HttpOnly/i', $hdr), trim(preg_replace('/\r?\n(?!Set-Cookie)[^\r\n]*/', '', $hdr)));
} finally {
    stop_mock($app);
}
if ($GLOBALS['fail']) echo implode("\n", array_slice(file("$tmp/app.log", FILE_IGNORE_NEW_LINES) ?: [], -10)) . "\n";
test_cleanup($tmp);
test_done();
