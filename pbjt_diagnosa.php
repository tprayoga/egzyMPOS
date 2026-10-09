<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // skrip CLI: tolak akses via web
/**
 * Diagnosa cepat kondisi server untuk temuan Skenario Uji (CLI, hanya membaca, tidak mengubah apa pun):
 *   sudo -u www-data php pbjt_diagnosa.php
 *
 * Per DB (oversight + tenant aktif): versi migrasi, tahun buku yang mencakup hari ini, 5 booking terakhir,
 * item menu & file gambarnya. Juga: folder upload bisa ditulis web server? nama tabel parkir di Linux.
 */
$c = require __DIR__ . '/application/config/pbjt_db.php';
date_default_timezone_set('Asia/Jakarta');
$m = @new mysqli($c['host'], $c['user'], $c['pass']);
if ($m->connect_errno) { fwrite(STDERR, "Koneksi DB gagal: {$m->connect_error}\n"); exit(1); }
$today = date('Y-m-d');
$q = function ($sql) use ($m) { $r = $m->query($sql); return $r ? $r->fetch_all(MYSQLI_ASSOC) : ['ERROR' => $m->error]; };
$migs = array_map(function ($f) { return substr(basename($f), 0, 3); }, glob(__DIR__ . '/application/migrations_pbjt/*.sql'));
$latest = $migs ? max($migs) : '-';

echo "== EGZY diagnosa " . date('Y-m-d H:i:s') . " (PHP " . PHP_VERSION . ", MariaDB " . $m->server_info . ", lower_case_table_names="
    . $m->query("SELECT @@lower_case_table_names")->fetch_row()[0] . ")\n";
echo "Migrasi terbaru di kode: v$latest\n\n";

$dbs = [$c['oversight_db']];
foreach ($q("SELECT db_name FROM `{$c['control_db']}`.tenants WHERE status=1 ORDER BY wp_id") as $r) if (isset($r['db_name'])) $dbs[] = $r['db_name'];
foreach ($dbs as $db) {
    $e = $m->real_escape_string($db);
    echo "## $db\n";
    $v = $q("SELECT MAX(version) v FROM `$e`.pbjt_schema_migrations");
    $ver = isset($v[0]['v']) ? $v[0]['v'] : '?';
    echo "  migrasi       : v$ver" . ($ver === $latest ? '' : "  <-- BELUM MUTAKHIR (jalankan pbjt_migrate_tenants.php)") . "\n";
    $fy = $q("SELECT title, start_date, end_date, is_active FROM `$e`.tbl_financialyear ORDER BY start_date");
    $ok = false;
    foreach ($fy as $f) if (is_array($f) && $f['start_date'] <= $today && $f['end_date'] >= $today && $f['is_active'] == '2') $ok = true;
    echo "  tahun buku    : " . ($ok ? 'OK (aktif mencakup hari ini)' : 'TIDAK ADA tahun buku aktif untuk hari ini  <-- booking/POS akan ditolak') . "\n";
    foreach ($fy as $f) if (is_array($f)) echo "                  {$f['title']} {$f['start_date']} s.d. {$f['end_date']} status={$f['is_active']} (2=aktif,0=nonaktif,1=tutup)\n";
    $bk = $q("SELECT bookedid, booking_number, date_time, bookingstatus, room_no, total_price FROM `$e`.booked_info ORDER BY bookedid DESC LIMIT 5");
    echo "  booking akhir : " . (isset($bk['ERROR']) ? $bk['ERROR'] : count($bk) . " baris") . "\n";
    if (!isset($bk['ERROR'])) foreach ($bk as $b) echo "                  #{$b['bookedid']} {$b['booking_number']} {$b['date_time']} status={$b['bookingstatus']} kamar={$b['room_no']} harga={$b['total_price']}\n";
    $fd = $q("SELECT ProductName, ProductImage, small_thumb FROM `$e`.item_foods ORDER BY ProductsID DESC LIMIT 5");
    if (!isset($fd['ERROR'])) foreach ($fd as $f) {
        $img = $f['ProductImage'];
        $st = $img === '' || $img === null ? 'TANPA GAMBAR (upload gagal / tidak diunggah)' : (is_file(__DIR__ . '/' . $img) ? 'file ada' : 'FILE HILANG: ' . $img);
        $sm = $f['small_thumb'] && !is_file(__DIR__ . '/' . $f['small_thumb']) ? ', thumbnail hilang' : '';
        echo "  menu          : {$f['ProductName']} -> $st$sm\n";
    }
    $pk = $q("SELECT table_name FROM information_schema.tables WHERE table_schema='$e' AND LOWER(table_name) IN ('tbl_bookparking','tbl_parkingsetting')");
    if (!isset($pk['ERROR'])) foreach ($pk as $t) if ($t['table_name'] !== strtolower($t['table_name'])) echo "  parkir        : tabel {$t['table_name']} berhuruf besar  <-- jalankan migrasi 011\n";
    echo "\n";
}

echo "## Folder upload (harus bisa ditulis user yang menjalankan skrip ini, mis. www-data)\n";
foreach (array_merge(glob(__DIR__ . '/application/modules/*/assets/images', GLOB_ONLYDIR), [__DIR__ . '/application/modules/ordermanage/assets/images/small', __DIR__ . '/uploads', __DIR__ . '/assets/img']) as $d) {
    $rel = substr($d, strlen(__DIR__) + 1);
    if (!is_dir($d)) { echo "  TIDAK ADA   $rel\n"; continue; }
    echo '  ' . (is_writable($d) ? 'OK        ' : 'TIDAK BISA DITULIS') . "  $rel\n";
}
echo "\nGD (resize gambar): " . (function_exists('imagecreatefromjpeg') ? 'ada' : 'TIDAK ADA  <-- pasang php-gd') . "\n";
