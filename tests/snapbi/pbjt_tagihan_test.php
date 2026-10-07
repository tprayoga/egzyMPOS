<?php
/**
 * Test Fase 2 — tagihan PBJT via VA (CLI):  php tests/snapbi/pbjt_tagihan_test.php
 *
 * Alur penuh: omzet -> terbit tagihan + VA (bank tiruan) -> isolasi tenant ->
 * terbit ulang saat nominal berubah -> notifikasi bayar bank -> tagihan lunas.
 * Memakai DB `snap_test` (salinan struktur tabel pbjt_* dari DB oversight).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PBJT_CLI', 1);

$root = dirname(__DIR__, 2);
require __DIR__ . '/_helpers.php';
require $root . '/application/modules/snapbi/libraries/Snap_service.php';
require $root . '/application/modules/snapbi/libraries/Snap_inbound.php';
require $root . '/application/modules/pbjt/libraries/Pbjt_engine.php';
require $root . '/application/modules/pbjt/libraries/Pbjt_va_handler.php';

$c = require $root . '/application/config/pbjt_db.php';
$db = test_db($root);
foreach (['pbjt_wp', 'pbjt_omzet_harian', 'pbjt_audit_log'] as $t) {
    $db->query("CREATE TABLE IF NOT EXISTS $t LIKE `{$c['oversight_db']}`.$t") or die($db->error);
}
$store = new Snap_store($db);
$store->migrate();
Pbjt_tagihan::migrate($db);

$tmp = test_tmpdir();
keypair($tmp, 'partner');
keypair($tmp, 'bank');
$port = 8792;
$run = getmypid();
$A = "TA$run"; $B = "TB$run";
$masa = '2026-08';
$db->query("INSERT INTO pbjt_wp (wp_id,nama,jenis_usaha,kecamatan) VALUES ('$A','Hotel Uji A','hotel','Soreang'),('$B','Resto Uji B','resto','Soreang')");
foreach ([['2026-08-03', 100000], ['2026-08-14', 150000], ['2026-08-30', 250000]] as $o) {
    $db->query("INSERT INTO pbjt_omzet_harian (wp_id,device_id,tanggal,omzet_bruto,pajak_terlapor) VALUES ('$A','TB-1','{$o[0]}'," . ($o[1] * 10) . ",{$o[1]})");
}

$cfg = [
    'token_ttl' => 900,
    'profiles'  => ['bapenda' => test_profile($port, $tmp, 'P' . $run . '-')],
    'handlers'  => ['pbjt' => function ($va) use ($db) { (new Pbjt_va_handler($db))->onPaid($va); }],
];
$svc = new Snap_service($cfg, $store);
$tag = new Pbjt_tagihan($db, ['tanggal_jatuh_tempo' => 15]);
$engine = new Pbjt_engine($db, ['control_db' => 'snap_test']);
$bapenda = ['role' => 'bapenda', 'wp_id' => null, 'user_id' => 1];
$ctxA = ['role' => 'wp', 'wp_id' => $A, 'user_id' => 2];
$ctxB = ['role' => 'wp', 'wp_id' => $B, 'user_id' => 3];
$none = ['role' => 'none', 'wp_id' => null, 'user_id' => 9];
$mine = function ($rows) use ($A, $B) { return array_values(array_filter($rows, function ($r) use ($A, $B) { return in_array($r['wp_id'], [$A, $B], true); })); };
$throws = function (callable $f, $needle) { try { $f(); return false; } catch (Throwable $e) { return strpos($e->getMessage(), $needle) !== false; } };

echo "1. Aturan masa & jatuh tempo\n";
check('masa lalu boleh ditagih', Pbjt_tagihan::masaSelesai('2026-08'));
check('masa berjalan tidak boleh', !Pbjt_tagihan::masaSelesai(date('Y-m')));
check('format masa salah ditolak', !Pbjt_tagihan::masaSelesai("2026-08' OR 1=1"));
check('jatuh tempo = tgl 15 bulan berikutnya', $tag->jatuhTempo('2026-08') === '2026-09-15', $tag->jatuhTempo('2026-08'));
check('jatuh tempo dipotong ke akhir bulan pendek', (new Pbjt_tagihan($db, ['tanggal_jatuh_tempo' => 31]))->jatuhTempo('2026-01') === '2026-02-28');
check('VA tepat waktu aktif s.d. akhir hari jatuh tempo', $tag->batasVa('2026-08', strtotime('2026-09-02 10:00')) === '2026-09-15 23:59:59');
check('VA terlambat aktif 7 hari dari terbit', $tag->batasVa('2026-08', strtotime('2026-10-06 10:00')) === '2026-10-13 23:59:59', $tag->batasVa('2026-08', strtotime('2026-10-06 10:00')));

$proc = start_mock($port, $tmp, 'EGZY-TEST', 'secret-out-123');
try {
    echo "2. Penerbitan tagihan + VA\n";
    list($t, $va) = $tag->terbitkan($A, $masa, $svc, 'bapenda', 'bapenda#1');
    check('tagihan = SUM(pajak_terlapor) = 500.000', (int) $t['pajak'] === 500000, $t['pajak']);
    check('VA diterima bank (pending) & tertaut ke tagihan', $va['status'] === 'pending' && (int) $t['snap_va_id'] === (int) $va['id'] && $t['va_no'] === trim($va['va_no']));
    check('VA memakai batas aktif tagihan (tidak lahir kedaluwarsa)', $va['expired_at'] === $tag->batasVa($masa) && strtotime($va['expired_at']) > time(), $va['expired_at']);
    check('WP tanpa pajak terutang ditolak', $throws(function () use ($tag, $B, $masa, $svc) { $tag->terbitkan($B, $masa, $svc, 'bapenda', 'x'); }, 'Tidak ada pajak terutang'));
    check('masa berjalan ditolak', $throws(function () use ($tag, $A, $svc) { $tag->terbitkan($A, date('Y-m'), $svc, 'bapenda', 'x'); }, 'belum berakhir'));
    list($t2, $va2) = $tag->terbitkan($A, $masa, $svc, 'bapenda', 'bapenda#1');
    check('terbit ulang tanpa perubahan memakai VA yang sama', (int) $va2['id'] === (int) $va['id']);

    echo "3. Isolasi tenant pada tagihan\n";
    $rows = $mine($engine->tagihan($bapenda, $masa));
    check('Bapenda melihat kedua WP', count($rows) === 2);
    $rA = $engine->tagihan($ctxA, $masa);
    check('WP A hanya melihat tagihannya (VA pending)', count($rA) === 1 && $rA[0]['wp_id'] === $A && $rA[0]['va_status'] === 'pending');
    $rB = $engine->tagihan($ctxB, $masa);
    check('WP B hanya melihat miliknya (belum terbit)', count($rB) === 1 && $rB[0]['wp_id'] === $B && !$rB[0]['tagihan_id']);
    check('user tak tertaut tidak melihat apa pun', $engine->tagihan($none, $masa) === []);
    check('WP B tidak bisa mengakses tagihan WP A', $engine->tagihanDalamScope($ctxB, $t['id']) === null);
    check('WP A bisa mengakses tagihannya', $engine->tagihanDalamScope($ctxA, $t['id']) === $A);

    echo "4. Nominal berubah -> VA lama ditutup\n";
    $db->query("INSERT INTO pbjt_omzet_harian (wp_id,device_id,tanggal,omzet_bruto,pajak_terlapor) VALUES ('$A','APP','2026-08-31',1000000,100000)");
    list($t3, $va3) = $tag->terbitkan($A, $masa, $svc, 'bapenda', 'bapenda#1');
    $old = $store->getVa($va['id']);
    check('tagihan diperbarui 600.000 dengan VA baru', (int) $t3['pajak'] === 600000 && (int) $va3['id'] !== (int) $va['id'] && $va3['status'] === 'pending');
    check('VA lama dihapus di bank & registry', $old['status'] === 'deleted');
    $mock = json_decode(file_get_contents("$tmp/mock_state.json"), true);
    check('bank tiruan tak lagi memegang VA lama', !isset($mock['va'][trim($va['va_no'])]) && isset($mock['va'][trim($va3['va_no'])]));
} finally {
    stop_mock($proc);
}

echo "5. Pembayaran dari bank\n";
$in = new Snap_inbound($cfg, $store, [$svc, 'dispatch']);
list($http, $r) = bank_pay($in, $tmp, $old, 500000, "OLD$run");
check('bayar ke VA lama ditolak (4042512)', $http === 404 && $r['responseCode'] === '4042512', json_encode($r));
list($http, $r) = bank_pay($in, $tmp, $va3, 500000, "X$run");
check('nominal kurang ditolak (4042513)', $http === 404 && $r['responseCode'] === '4042513', json_encode($r));
list($http, $r) = bank_pay($in, $tmp, $va3, 600000, "PAY$run");
$t4 = $tag->get($t3['id']);
check('bayar penuh diterima (2002500)', $http === 200 && $r['responseCode'] === '2002500', json_encode($r));
check('tagihan LUNAS, dibayar 600.000', $t4['status'] === 'lunas' && (int) $t4['dibayar'] === 600000 && $t4['lunas_pada']);
check('VA tercatat sudah diteruskan', (bool) $store->getVa($va3['id'])['dispatched_at']);
$audit = $db->query("SELECT COUNT(*) FROM pbjt_audit_log WHERE aksi='tagihan-lunas' AND detail LIKE '$A %'")->fetch_row()[0];
check('pelunasan tercatat di audit log', (int) $audit === 1);
check('dasbor WP A menampilkan lunas', $engine->tagihan($ctxA, $masa)[0]['status'] === 'lunas');
check('tagihan lunas tidak bisa diterbitkan ulang', $throws(function () use ($tag, $A, $masa, $svc) { $tag->terbitkan($A, $masa, $svc, 'bapenda', 'x'); }, 'sudah lunas'));
check('handler menolak VA yang bukan VA aktif tagihan', $throws(function () use ($tag, $old) {
    $fake = $old; $fake['ref'] = (string) $GLOBALS['t3']['id'];
    $GLOBALS['db']->query("UPDATE pbjt_tagihan SET status='terbit' WHERE id=" . (int) $GLOBALS['t3']['id']);
    $tag->tandaiLunas($fake);
}, 'bukan VA aktif'));

test_cleanup($tmp);
test_done();
