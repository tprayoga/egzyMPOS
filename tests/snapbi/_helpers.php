<?php
/** Alat bantu bersama untuk test SNAP BI. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check($name, $cond, $extra = '')
{
    if ($cond) { $GLOBALS['pass']++; echo "  [OK]   $name\n"; }
    else { $GLOBALS['fail']++; echo "  [GAGAL] $name" . ($extra !== '' ? "  -> $extra" : '') . "\n"; }
}

function keypair($dir, $name)
{
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($k, $priv);
    file_put_contents("$dir/{$name}_private.pem", $priv);
    file_put_contents("$dir/{$name}_public.pem", openssl_pkey_get_details($k)['key']);
}

function test_tmpdir()
{
    $tmp = sys_get_temp_dir() . '/snapbi_test_' . getmypid() . '_' . bin2hex(random_bytes(3));
    @mkdir($tmp, 0700, true);
    return $tmp;
}

function test_cleanup($tmp)
{
    array_map('unlink', glob("$tmp/*"));
    @rmdir($tmp);
}

/** DB uji terpisah `snap_test` (registry SNAP + tabel oversight tiruan). */
function test_db($root)
{
    $c = require $root . '/application/config/pbjt_db.php';
    $m = new mysqli($c['host'], $c['user'], $c['pass']);
    $m->query('CREATE DATABASE IF NOT EXISTS snap_test CHARACTER SET utf8mb4');
    $m->close();
    return new mysqli($c['host'], $c['user'], $c['pass'], 'snap_test');
}

/** Jalankan bank tiruan; mengembalikan resource proses (hentikan dengan stop_mock). */
function start_mock($port, $tmp, $clientId, $clientSecret)
{
    $env = sprintf('MOCK_CLIENT_ID=%s MOCK_CLIENT_SECRET=%s MOCK_PARTNER_PUB=%s MOCK_STATE=%s',
        escapeshellarg($clientId), escapeshellarg($clientSecret), escapeshellarg("$tmp/partner_public.pem"), escapeshellarg("$tmp/mock_state.json"));
    $proc = proc_open("exec env $env php -S 127.0.0.1:$port " . escapeshellarg(__DIR__ . '/mock_bank.php'),
        [0 => ['pipe', 'r'], 1 => ['file', "$tmp/mock.log", 'a'], 2 => ['file', "$tmp/mock.log", 'a']], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    return $proc;
}

function stop_mock($proc)
{
    proc_terminate($proc);
    proc_close($proc);
}

/** Profil SNAP uji yang menunjuk ke bank tiruan. */
function test_profile($port, $tmp, $prefix)
{
    return [
        'base_url'           => "http://127.0.0.1:$port",
        'client_id'          => 'EGZY-TEST',
        'client_secret'      => 'secret-out-123',
        'partner_id'         => 'EGZYPARTNER',
        'channel_id'         => '95231',
        'private_key'        => "$tmp/partner_private.pem",
        'partner_service_id' => '98829',
        'customer_no_length' => 8,
        'trx_prefix'         => $prefix,
        'paths'              => ['mark' => '/__mark_paid'],
        'inbound' => [
            'client_key'    => 'BNI-TEST',
            'public_key'    => "$tmp/bank_public.pem",
            'client_secret' => 'secret-in-456',
        ],
    ];
}

/** Kirim notifikasi pembayaran bertanda tangan seperti bank ke Snap_inbound. */
function bank_pay(Snap_inbound $in, $tmp, array $va, $amount, $paymentRequestId)
{
    $ts = Snap_sign::timestamp();
    list(, $r) = $in->handle('POST', '/v1.0/access-token/b2b',
        ['X-TIMESTAMP' => $ts, 'X-CLIENT-KEY' => 'BNI-TEST', 'X-SIGNATURE' => Snap_sign::asymmetric('BNI-TEST', $ts, file_get_contents("$tmp/bank_private.pem"))],
        '{"grantType":"client_credentials"}');
    $token = $r['accessToken'];
    $path = '/v1.0/transfer-va/payment';
    $body = json_encode(['partnerServiceId' => $va['partner_service_id'], 'customerNo' => $va['customer_no'], 'virtualAccountNo' => $va['va_no'],
        'paymentRequestId' => $paymentRequestId, 'paidAmount' => ['value' => Snap_sign::amount($amount), 'currency' => 'IDR']]);
    return $in->handle('POST', $path, [
        'Authorization' => "Bearer $token", 'X-TIMESTAMP' => $ts,
        'X-SIGNATURE'   => Snap_sign::symmetric('POST', $path, $token, $body, $ts, 'secret-in-456'),
        'X-PARTNER-ID'  => 'BNI', 'X-EXTERNAL-ID' => Snap_sign::externalId(), 'CHANNEL-ID' => '95231',
    ], $body);
}

function test_done()
{
    echo "\nHASIL: {$GLOBALS['pass']} lulus, {$GLOBALS['fail']} gagal\n";
    exit($GLOBALS['fail'] ? 1 : 0);
}
