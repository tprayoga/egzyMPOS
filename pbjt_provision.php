<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // skrip CLI: tolak akses via web
$__db = require __DIR__ . '/application/config/pbjt_db.php'; // kredensial terpusat
/**
 * CLI provisioning tenant WP baru (silo).
 * Usage:
 *   php pbjt_provision.php <WP_ID> "<Nama>" <hotel|resto> "<Kecamatan>" <email_admin> [password]
 * Contoh:
 *   php pbjt_provision.php WP007 "Villa Asri" hotel "Cidadap" villa007@pbjt.local
 */
define('PBJT_CLI', true);
require __DIR__ . '/application/modules/pbjt/libraries/Pbjt_provision.php';

if ($argc < 6) {
    fwrite(STDERR, "Usage: php pbjt_provision.php <WP_ID> \"<Nama>\" <hotel|resto> \"<Kecamatan>\" <email_admin> [password]\n");
    exit(1);
}
list(, $wp, $nama, $jenis, $kec, $email) = $argv;
require_once __DIR__ . '/application/helpers/egzy_auth_helper.php';
$pass = $argv[6] ?? egzy_password_random(); // tanpa argumen: password acak, dicetak sekali

$m = @new mysqli($__db['host'], $__db['user'], $__db['pass'], '');
if ($m->connect_errno) { fwrite(STDERR, "Koneksi gagal: " . $m->connect_error . "\n"); exit(1); }

try {
    $p = new Pbjt_provision($m);
    $t0 = microtime(true);
    $db = $p->createTenant($wp, $nama, $jenis, $kec, $email, $pass);
    printf("Tenant dibuat: %s -> DB `%s` (%.1fs)\n", $wp, $db, microtime(true) - $t0);
    echo "  Login    : $email / $pass (admin operasional tenant)\n";
    echo "  Terdaftar: registry tenants, direktori login, master WP oversight\n";
    echo "  Coba     : buka /login, masukkan email di atas -> otomatis masuk instance $nama\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "GAGAL: " . $e->getMessage() . "\n");
    exit(1);
}
$m->close();
