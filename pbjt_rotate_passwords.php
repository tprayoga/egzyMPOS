<?php
/**
 * Rotasi password akun login (CLI saja). Dipakai setelah memuat data demo ke server.
 *
 *   sudo -u www-data php pbjt_rotate_passwords.php            semua akun di direktori login
 *   sudo -u www-data php pbjt_rotate_passwords.php <email>    satu akun
 *
 * - Akun admin (Bapenda & tiap tenant, dari pbjt_control.directory): password ACAK baru,
 *   dicetak SEKALI di layar — simpan di pengelola sandi.
 * - Akun tamu website data demo (*@egzy.test): dikunci dengan password acak yang tidak
 *   dicetak; tamu sungguhan memakai "lupa password".
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$c = require __DIR__ . '/application/config/pbjt_db.php';
require __DIR__ . '/application/helpers/egzy_auth_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$only = isset($argv[1]) ? strtolower(trim($argv[1])) : null;
$ctl = new mysqli($c['host'], $c['user'], $c['pass'], $c['control_db']);
$rows = $ctl->query("SELECT email, db_name FROM directory ORDER BY is_bapenda DESC, email")->fetch_all(MYSQLI_ASSOC);
if ($only) $rows = array_values(array_filter($rows, function ($r) use ($only) { return strtolower($r['email']) === $only; }));
if (!$rows) { fwrite(STDERR, $only ? "Email $only tidak ada di direktori login.\n" : "Direktori login kosong.\n"); exit(1); }

$hasil = [];
$tamuDikunci = 0;
$dbs = [];
foreach ($rows as $r) {
    $db = new mysqli($c['host'], $c['user'], $c['pass'], $r['db_name']);
    $pw = egzy_password_random(16);
    $st = $db->prepare("UPDATE user SET password=?, password_reset_token='' WHERE email=?");
    $hash = egzy_password_hash($pw);
    $st->bind_param('ss', $hash, $r['email']);
    $st->execute();
    $hasil[] = [$r['email'], $r['db_name'], $st->affected_rows > 0 ? $pw : '(akun tidak ditemukan di DB)'];
    $dbs[$r['db_name']] = $db;
}
if (!$only) {
    foreach ($dbs as $name => $db) {
        foreach ($db->query("SELECT customerid FROM customerinfo WHERE email LIKE '%@egzy.test'")->fetch_all() as $t) {
            $h = egzy_password_hash(egzy_password_random(24));
            $st = $db->prepare("UPDATE customerinfo SET pass=? WHERE customerid=?");
            $st->bind_param('si', $h, $t[0]);
            $st->execute();
            $tamuDikunci++;
        }
    }
}

echo "\nPASSWORD BARU — ditampilkan sekali, simpan sekarang di pengelola sandi:\n\n";
foreach ($hasil as $h) printf("  %-28s %-18s %s\n", $h[0], $h[1], $h[2]);
if (!$only) echo "\nAkun tamu demo (*@egzy.test) dikunci: $tamuDikunci\n";
echo "\n";
