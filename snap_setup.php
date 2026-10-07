<?php
/**
 * Setup & alat bantu SNAP BI (CLI saja).
 *
 *   php snap_setup.php migrate            buat tabel snap_* (DB kontrol) + pbjt_tagihan (DB oversight), idempoten
 *   php snap_setup.php check              validasi profil di snap_bi.local.php
 *   php snap_setup.php token <profil>     uji ambil token B2B ke bank
 *   php snap_setup.php redispatch         ulangi penerusan VA lunas yang tertunda
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('BASEPATH', __DIR__ . '/system/');
$APP = __DIR__ . '/application/';
require $APP . 'modules/snapbi/libraries/Snap_service.php';

$cfg = require $APP . 'config/snap_bi.php';
$cmd = isset($argv[1]) ? $argv[1] : '';

try {
    $store = Snap_store::connect();
    $svc = new Snap_service($cfg, $store);

    switch ($cmd) {
        case 'migrate':
            $store->migrate();
            echo "OK: tabel snap_* siap di DB kontrol.\n";
            require_once $APP . 'modules/pbjt/libraries/Pbjt_tagihan.php';
            $c = require $APP . 'config/pbjt_db.php';
            $ov = new mysqli($c['host'], $c['user'], $c['pass'], $c['oversight_db']);
            Pbjt_tagihan::migrate($ov);
            echo "OK: tabel pbjt_tagihan siap di DB oversight ({$c['oversight_db']}).\n";
            break;

        case 'check':
            if (empty($cfg['profiles'])) {
                echo "Belum ada profil. Salin application/config/snap_bi.local.php.example -> snap_bi.local.php\n";
                exit(1);
            }
            $bad = 0;
            foreach ($cfg['profiles'] as $name => $p) {
                echo "[$name]\n";
                foreach (['base_url', 'client_id', 'client_secret', 'partner_id', 'channel_id', 'private_key', 'partner_service_id'] as $k) {
                    $v = isset($p[$k]) ? (string) $p[$k] : '';
                    $ok = $v !== '' && strpos($v, '[ISI') === false;
                    if ($k === 'private_key' && $ok) $ok = (bool) openssl_pkey_get_private(Snap_sign::pem($v));
                    printf("  %-20s %s\n", $k, $ok ? 'OK' : 'BELUM/SALAH');
                    $bad += $ok ? 0 : 1;
                }
                $in = isset($p['inbound']) ? $p['inbound'] : [];
                foreach (['client_key', 'public_key', 'client_secret'] as $k) {
                    $v = isset($in[$k]) ? (string) $in[$k] : '';
                    $ok = $v !== '' && strpos($v, '[ISI') === false;
                    if ($k === 'public_key' && $ok) $ok = (bool) openssl_pkey_get_public(Snap_sign::pem($v));
                    printf("  inbound.%-12s %s\n", $k, $ok ? 'OK' : 'BELUM/SALAH');
                    $bad += $ok ? 0 : 1;
                }
            }
            exit($bad ? 1 : 0);

        case 'token':
            $name = isset($argv[2]) ? $argv[2] : '';
            $t = $svc->client($name)->accessToken(true);
            echo "OK: token diterima (" . strlen($t) . " karakter).\n";
            break;

        case 'redispatch':
            // Handler booking butuh CodeIgniter (pembukuan); jalankan lewat front controller.
            passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/index.php') . ' snapbi/snap/redispatch', $rc);
            exit($rc);

        default:
            echo "Pakai: php snap_setup.php migrate|check|token <profil>|redispatch\n";
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
