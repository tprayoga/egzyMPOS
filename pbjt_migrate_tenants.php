<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // skrip CLI: tolak akses via web
$__db = require __DIR__ . '/application/config/pbjt_db.php'; // kredensial terpusat
/**
 * Migrasi skema untuk arsitektur SILO.
 * Menerapkan file migrasi (application/migrations_pbjt/NNN_*.sql) ke:
 *   - DB oversight (hotelv3: aplikasi Bapenda, skema aplikasi yang sama),
 *   - tenant_template (agar tenant BARU lahir sudah ter-migrasi), dan
 *   - SEMUA DB tenant aktif (dari pbjt_control.tenants).
 * Idempoten: tiap DB punya tabel `pbjt_schema_migrations` yang mencatat versi terpasang.
 *
 * Usage:
 *   php pbjt_migrate_tenants.php            # terapkan migrasi yang belum ada
 *   php pbjt_migrate_tenants.php --status   # tampilkan status tanpa mengubah apa pun
 */
$MIG_DIR = __DIR__ . '/application/migrations_pbjt';
$TEMPLATE = 'tenant_template';
$CONTROL = $__db['control_db'];
$statusOnly = in_array('--status', $argv, true);

$m = @new mysqli($__db['host'], $__db['user'], $__db['pass'], '');
if ($m->connect_errno) { fwrite(STDERR, "Koneksi gagal: " . $m->connect_error . "\n"); exit(1); }

// Daftar migrasi (urut versi).
$files = glob("$MIG_DIR/*.sql");
sort($files, SORT_STRING);
$migrations = [];
foreach ($files as $f) {
    if (preg_match('/^(\d+)_/', basename($f), $mm)) $migrations[$mm[1]] = $f;
}
if (!$migrations) { echo "Tidak ada file migrasi di $MIG_DIR\n"; exit(0); }

// Target: DB oversight (aplikasi Bapenda, skema sama) + template + semua tenant aktif.
$targets = [$__db['oversight_db'], $TEMPLATE];
$r = $m->query("SELECT db_name FROM `$CONTROL`.tenants WHERE status=1 ORDER BY wp_id");
while ($x = $r->fetch_row()) $targets[] = $x[0];

echo ($statusOnly ? "STATUS migrasi" : "MENERAPKAN migrasi") . " | file: " . count($migrations) . " | target DB: " . count($targets) . "\n";
echo str_repeat('-', 64) . "\n";

$totalApplied = 0;
foreach ($targets as $db) {
    // Lewati bila DB tak ada.
    $ex = $m->query("SELECT 1 FROM information_schema.schemata WHERE schema_name='" . $m->real_escape_string($db) . "'");
    if (!$ex || !$ex->num_rows) { echo "  [$db] TIDAK ADA — dilewati\n"; continue; }

    $m->select_db($db);
    $m->query("CREATE TABLE IF NOT EXISTS pbjt_schema_migrations (version VARCHAR(20) PRIMARY KEY, applied_at DATETIME NOT NULL)");
    $applied = [];
    $ar = $m->query("SELECT version FROM pbjt_schema_migrations");
    while ($x = $ar->fetch_row()) $applied[$x[0]] = 1;

    $pending = array_diff(array_keys($migrations), array_keys($applied));
    sort($pending, SORT_STRING);

    if (!$pending) { echo "  [$db] mutakhir (v" . (max(array_keys($applied) ?: ['-'])) . ")\n"; continue; }
    if ($statusOnly) { echo "  [$db] tertunda: " . implode(', ', $pending) . "\n"; continue; }

    foreach ($pending as $ver) {
        $sql = file_get_contents($migrations[$ver]);
        // Jalankan semua pernyataan; next_result() = false berarti pernyataan berikutnya ERROR.
        $err = '';
        if (!$m->multi_query($sql)) {
            $err = $m->error;
        } else {
            while (true) {
                if ($res = $m->store_result()) $res->free();
                if (!$m->more_results()) break;
                if (!$m->next_result()) { $err = $m->error; break; }
            }
        }

        if ($err) { echo "  [$db] GAGAL di v$ver: $err (berhenti utk DB ini)\n"; $failed = true; break; }
        $now = date('Y-m-d H:i:s');
        $m->query("INSERT INTO pbjt_schema_migrations (version, applied_at) VALUES ('" . $m->real_escape_string($ver) . "', '$now')");
        echo "  [$db] + v$ver diterapkan\n";
        $totalApplied++;
    }
}
echo str_repeat('-', 64) . "\n";
echo $statusOnly ? "Selesai (status saja).\n" : "Selesai. Total migrasi diterapkan: $totalApplied\n";
$m->close();
exit(!empty($failed) ? 1 : 0); // kode keluar non-nol bila ada migrasi gagal (dipakai skrip deploy)
