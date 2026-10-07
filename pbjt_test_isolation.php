<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // skrip CLI: tolak akses via web
$__db = require __DIR__ . '/application/config/pbjt_db.php'; // kredensial terpusat
/**
 * Uji isolasi tenant. Membuktikan bahwa aturan tenantWhere() menegakkan:
 *   - Bapenda (super-admin)  : lihat SEMUA WP + antrean MID tak terpetakan.
 *   - WP                     : HANYA lihat data wp_id miliknya, tanpa antrean global.
 *   - user tak tertaut       : tidak lihat apa pun.
 *
 * Jalankan: php pbjt_test_isolation.php
 */
define('PBJT_CLI', true);
require __DIR__ . '/application/modules/pbjt/libraries/Pbjt_engine.php';

$masa = '2026-08';
$m = new mysqli($__db['host'], $__db['user'], $__db['pass'], 'hotelv3');
if ($m->connect_errno) { fwrite(STDERR, $m->connect_error); exit(1); }
$engine = new Pbjt_engine($m);

function rp($n){ return 'Rp ' . number_format((int)$n,0,',','.'); }

// Simulasikan konteks dari sesi aplikasi (user_id, is_admin).
$skenario = [
    'Bapenda (admin, id=1)'   => $engine->contextFromSession(1, 1),
    'User WP001 (id=2)'       => $engine->contextFromSession(2, 0),
    'User WP002 (id=3)'       => $engine->contextFromSession(3, 0),
    'User tak tertaut (id=9)' => $engine->contextFromSession(9, 0),
];

foreach ($skenario as $label => $ctx) {
    echo str_repeat('-', 70) . "\n";
    echo "$label  ->  role={$ctx['role']}  wp_id=" . ($ctx['wp_id'] ?? '(semua)') . "\n";
    $kpi = $engine->kpi($ctx, $masa);
    $temuan = $engine->temuan($ctx, $masa);
    $unmapped = $engine->unmapped($ctx, $masa);
    echo "  KPI  : temuan={$kpi['jml_temuan']}  potensi=" . rp($kpi['total_potensi'])
        . "  unmapped={$kpi['unmapped_n']} (" . rp($kpi['unmapped_rp']) . ")\n";
    echo "  Temuan yang TERLIHAT:\n";
    if (!$temuan) { echo "    (tidak ada)\n"; }
    foreach ($temuan as $t) {
        echo "    - {$t['wp_id']} {$t['nama']} | potensi " . rp($t['potensi_rp']) . "\n";
    }
    echo "  Antrean MID tak terpetakan terlihat: " . count($unmapped) . "\n";
}
echo str_repeat('-', 70) . "\n";

// Verifikasi tegas — sifat isolasi (tidak bergantung pada angka data contoh).
$semua = $engine->temuan($engine->contextFromSession(1,1), $masa);
$totalAll = (int) $m->query("SELECT COUNT(*) FROM pbjt_temuan WHERE masa='$masa'")->fetch_row()[0];
$cek = [];
$cek['Bapenda melihat semua temuan'] = count($semua) === $totalAll;
foreach ([2 => 'WP001', 3 => 'WP002'] as $uid => $wp) {
    $ctx = $engine->contextFromSession($uid, 0);
    $rows = $engine->temuan($ctx, $masa);
    $milikSendiri = array_filter($rows, fn($r) => $r['wp_id'] === $wp);
    $harapan = count(array_filter($semua, fn($r) => $r['wp_id'] === $wp));
    $cek["$wp hanya melihat miliknya"] = count($rows) === count($milikSendiri) && count($rows) === $harapan;
    $cek["$wp tak melihat antrean global"] = count($engine->unmapped($ctx, $masa)) === 0;
}
$cek['User tak tertaut tak melihat apa pun'] = count($engine->temuan($engine->contextFromSession(9,0), $masa)) === 0;
foreach ($cek as $k => $v) echo ($v ? "  [OK]    " : "  [GAGAL] ") . $k . "\n";
$ok = !in_array(false, $cek, true);
echo $ok ? "HASIL: ISOLASI TENANT OK\n" : "HASIL: ISOLASI GAGAL\n";
$m->close();
