<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // skrip CLI: tolak akses via web
$__db = require __DIR__ . '/application/config/pbjt_db.php'; // kredensial terpusat
/**
 * Fondasi multi-tenant SILO (database per-tenant).
 *
 * - Membuat DB kontrol `pbjt_control` berisi registry tenant + direktori login.
 * - Provisioning DB per-WP (`tenant_wp001`, `tenant_wp002`) = klon skema+data aplikasi (hotelv3),
 *   tabel pbjt_* (sisi Bapenda) TIDAK ikut diklon.
 * - Memberi tiap tenant identitas berbeda (storename) untuk membuktikan isolasi.
 *
 * Jalankan: php pbjt_silo_setup.php
 */
$SRC = 'hotelv3';
$tenants = [
    // wp_id, nama, db_name, jenis, kecamatan, email login, storename
    ['WP001', 'Hotel Melati Indah', 'tenant_wp001', 'hotel', 'Coblong',  'wp001@pbjt.local', 'Hotel Melati Indah'],
    ['WP002', 'Hotel Serena',       'tenant_wp002', 'hotel', 'Lengkong', 'wp002@pbjt.local', 'Hotel Serena'],
];

$m = @new mysqli($__db['host'], $__db['user'], $__db['pass'], '');
if ($m->connect_errno) { fwrite(STDERR, "Koneksi gagal: " . $m->connect_error . "\n"); exit(1); }

/* ---------- 1) DB kontrol + registry ---------- */
$m->query("CREATE DATABASE IF NOT EXISTS pbjt_control CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$m->select_db('pbjt_control');
$m->query("CREATE TABLE IF NOT EXISTS tenants (
    wp_id VARCHAR(32) PRIMARY KEY, nama VARCHAR(150) NOT NULL, db_name VARCHAR(64) NOT NULL UNIQUE,
    jenis_usaha VARCHAR(20) NOT NULL, kecamatan VARCHAR(80) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1, dibuat DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$m->query("CREATE TABLE IF NOT EXISTS directory (
    email VARCHAR(120) PRIMARY KEY, wp_id VARCHAR(32) NULL, db_name VARCHAR(64) NULL,
    is_bapenda TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$m->query("TRUNCATE tenants"); $m->query("TRUNCATE directory");

// Bapenda (pengawas) tidak terikat tenant; login diarahkan ke DB kontrol/oversight (hotelv3 utk PoC).
$m->query("INSERT INTO directory (email, wp_id, db_name, is_bapenda) VALUES ('admin@gmail.com', NULL, 'hotelv3', 1)");

/* ---------- 2) Daftar tabel aplikasi yang diklon (exclude pbjt_*) ---------- */
$m->select_db($SRC);
$tables = [];
$r = $m->query("SHOW TABLES");
while ($x = $r->fetch_row()) { if (strpos($x[0], 'pbjt_') !== 0) $tables[] = $x[0]; }

/* ---------- 3) Provisioning tiap tenant ---------- */
$now = date('Y-m-d H:i:s');
foreach ($tenants as $t) {
    list($wp, $nama, $db, $jenis, $kec, $email, $storename) = $t;

    $m->query("DROP DATABASE IF EXISTS `$db`");
    $m->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

    // Klon skema + data tiap tabel (CREATE TABLE LIKE tak menyalin FK -> aman untuk urutan).
    $m->query("SET FOREIGN_KEY_CHECKS=0");
    foreach ($tables as $tb) {
        $m->query("CREATE TABLE `$db`.`$tb` LIKE `$SRC`.`$tb`");
        $m->query("INSERT INTO `$db`.`$tb` SELECT * FROM `$SRC`.`$tb`");
    }
    $m->query("SET FOREIGN_KEY_CHECKS=1");

    // Identitas tenant berbeda (bukti isolasi).
    $m->query("UPDATE `$db`.setting SET storename='" . $m->real_escape_string($storename) . "'");
    $m->query("UPDATE `$db`.common_setting SET storename='" . $m->real_escape_string($storename) . "'");

    // Registry + direktori login.
    $m->select_db('pbjt_control');
    $ins = $m->prepare("INSERT INTO tenants (wp_id,nama,db_name,jenis_usaha,kecamatan,status,dibuat) VALUES (?,?,?,?,?,1,?)");
    $ins->bind_param('ssssss', $wp, $nama, $db, $jenis, $kec, $now); $ins->execute(); $ins->close();
    $ins = $m->prepare("INSERT INTO directory (email,wp_id,db_name,is_bapenda) VALUES (?,?,?,0)");
    $ins->bind_param('sss', $email, $wp, $db); $ins->execute(); $ins->close();
    $m->select_db($SRC);

    $cnt = $m->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db'")->fetch_row()[0];
    echo "  Provisioned $wp -> `$db` ($cnt tabel), store='$storename', login=$email\n";
}

echo "Silo siap. Tenant: " . count($tenants) . " | tabel aplikasi diklon: " . count($tables) . "\n";
$m->close();
