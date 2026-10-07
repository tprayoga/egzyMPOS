<?php
/**
 * Test SNAP BI (CLI):  php tests/snapbi/run_tests.php [--app=http://127.0.0.1:8747]
 *
 *  1. Unit: minify, body hash, signature asimetris/simetris.
 *  2. Keluar: aplikasi -> bank tiruan (token, create VA, status) dengan signature diverifikasi bank.
 *  3. Masuk : bank -> aplikasi (token, inquiry, payment) termasuk kasus negatif & idempoten.
 *  4. Smoke HTTP (opsional): rute + pengecualian CSRF di aplikasi yang sedang jalan.
 *
 * Memakai DB terpisah `snap_test` (dibuat bila belum ada); tidak menyentuh DB kontrol.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__, 2);
require __DIR__ . '/_helpers.php';
require $root . '/application/modules/snapbi/libraries/Snap_service.php';
require $root . '/application/modules/snapbi/libraries/Snap_inbound.php';

$opts = getopt('', ['app::']);
$appUrl = isset($opts['app']) ? rtrim($opts['app'], '/') : null;

// ---------------------------------------------------------------- persiapan
$tmp = test_tmpdir();
keypair($tmp, 'partner');
keypair($tmp, 'bank');

$store = new Snap_store(test_db($root));
$store->migrate();

$port = 8791;
$cfg = [
    'token_ttl' => 900,
    'handlers'  => [],
    'profiles'  => [
        'test' => test_profile($port, $tmp, 'T' . getmypid() . '-'),
    ],
];
$dispatched = [];
$cfg['handlers']['test'] = function ($va) use (&$dispatched) { $dispatched[] = $va['id']; };

// --------------------------------------------------------------- 1. unit
echo "1. Unit signature\n";
check('minify menjaga spasi di dalam string', Snap_sign::minify("{ \"a\" : \"x y\",\n \"b\": [1, 2] }") === '{"a":"x y","b":[1,2]}');
check('minify menangani escape kutip', Snap_sign::minify('{"a": "q\\" z"}') === '{"a":"q\\" z"}');
check('bodyHash = sha256 hex lowercase body minified', Snap_sign::bodyHash('{ "a": 1 }') === hash('sha256', '{"a":1}'));
$ts = Snap_sign::timestamp(1790000000);
check('X-TIMESTAMP ISO-8601 +07:00', $ts === '2026-09-21T21:13:20+07:00', $ts);
$sig = Snap_sign::asymmetric('CK', $ts, file_get_contents("$tmp/partner_private.pem"));
check('asimetris terverifikasi dengan public key', Snap_sign::verifyAsymmetric('CK', $ts, $sig, file_get_contents("$tmp/partner_public.pem")));
check('asimetris ditolak bila timestamp beda', !Snap_sign::verifyAsymmetric('CK', $ts . 'x', $sig, file_get_contents("$tmp/partner_public.pem")));
$expected = base64_encode(hash_hmac('sha512', 'POST:/p:TOK:' . hash('sha256', '{"a":1}') . ':' . $ts, 'S', true));
check('simetris sesuai rumus SNAP', Snap_sign::symmetric('post', '/p', 'TOK', '{ "a": 1 }', $ts, 'S') === $expected);

// ------------------------------------------------------ 2. keluar (mock)
echo "2. Keluar: aplikasi -> bank tiruan\n";
$proc = start_mock($port, $tmp, 'EGZY-TEST', 'secret-out-123');

try {
    $svc = new Snap_service($cfg, $store);
    $ref = 'REF-' . getmypid();
    $va = $svc->issue('test', 'test', null, $ref, 'Hotel Uji', 1250000, '+1 day');
    check('create VA diterima bank -> status pending', $va['status'] === 'pending', $va['status'] . ' ' . $va['bank_response']);
    check('virtualAccountNo = partnerServiceId(8, pad spasi) + customerNo', $va['va_no'] === '   98829' . $va['customer_no'] && strlen($va['customer_no']) === 8, json_encode($va['va_no']));
    $again = $svc->issue('test', 'test', null, $ref, 'Hotel Uji', 1250000, '+1 day');
    check('issue ulang ref sama memakai VA pending yang ada', $again['id'] === $va['id']);
    check('token keluar di-cache', $store->getOutboundToken('test') !== null);

    list($http, $res, $row) = $svc->checkStatus($va['id']);
    check('inquiry status: belum bayar', $http === 200 && $row['status'] === 'pending', "$http " . json_encode($res));

    $client = $svc->client('test');
    $client->call('POST', 'mark', ['virtualAccountNo' => $va['va_no']]);
    list($http, $res, $row) = $svc->checkStatus($va['id']);
    check('inquiry status: lunas -> registry paid + dispatch', $row['status'] === 'paid' && in_array($va['id'], $dispatched), json_encode($row['status']));

    $store->clearOutboundToken('test');
    $store->saveOutboundToken('test', 'token-basi', 900);
    list($http) = $client->inquiryStatus($va);
    check('token ditolak bank -> diperbarui otomatis & retry', $http === 200, "HTTP $http");

    $bad = $cfg['profiles']['test'];
    $bad['client_secret'] = 'salah';
    $store->clearOutboundToken('bad');
    list($http, $res) = (new Snap_client('bad', $bad, $store))->inquiryStatus($va);
    check('secret salah -> bank menolak signature', $http === 401, "HTTP $http");
} finally {
    stop_mock($proc);
}

// ------------------------------------------------------ 3. masuk (bank -> app)
echo "3. Masuk: bank -> aplikasi\n";
$dispatched = [];
$cfg['handlers']['test'] = function ($va) use (&$dispatched) { $dispatched[] = $va['id']; };
$svc = new Snap_service($cfg, $store);
$in = new Snap_inbound($cfg, $store, [$svc, 'dispatch']);
$bankPriv = file_get_contents("$tmp/bank_private.pem");

// VA pending baru disisipkan langsung (tanpa bank) untuk menguji alur masuk.
$va = $store->insertVa(['profile' => 'test', 'purpose' => 'test', 'tenant_db' => null, 'ref' => 'IN-' . getmypid(),
    'partner_service_id' => '   98829', 'va_name' => 'Tamu Uji', 'amount' => 500000, 'currency' => 'IDR',
    'expired_at' => date('Y-m-d H:i:s', time() + 3600), 'trx_prefix' => 'I' . getmypid() . '-'], 8);
$store->setStatus($va['id'], 'pending');

$ts = Snap_sign::timestamp();
$tokReq = function ($sig, $key = 'BNI-TEST') use ($in, $ts) {
    return $in->handle('POST', '/v1.0/access-token/b2b',
        ['X-TIMESTAMP' => $ts, 'X-CLIENT-KEY' => $key, 'X-SIGNATURE' => $sig, 'Content-Type' => 'application/json'],
        '{"grantType":"client_credentials"}');
};
list($http, $r) = $tokReq(Snap_sign::asymmetric('BNI-TEST', $ts, $bankPriv));
check('token B2B diterbitkan (2007300)', $http === 200 && $r['responseCode'] === '2007300' && !empty($r['accessToken']), json_encode($r));
$token = $r['accessToken'];
list($http, $r) = $tokReq(Snap_sign::asymmetric('BNI-TEST', $ts, file_get_contents("$tmp/partner_private.pem")));
check('token: signature kunci lain ditolak (4017300)', $http === 401 && $r['responseCode'] === '4017300', json_encode($r));
list($http, $r) = $tokReq(Snap_sign::asymmetric('ASING', $ts, $bankPriv), 'ASING');
check('token: client key tak dikenal ditolak', $http === 401, json_encode($r));

$call = function ($path, array $payload, $opt = []) use (&$in, &$token) {
    $body = json_encode($payload);
    $ts = Snap_sign::timestamp();
    $tok = isset($opt['token']) ? $opt['token'] : $token;
    $secret = isset($opt['secret']) ? $opt['secret'] : 'secret-in-456';
    return $in->handle('POST', $path, [
        'Authorization' => "Bearer $tok", 'X-TIMESTAMP' => $ts,
        'X-SIGNATURE'   => Snap_sign::symmetric('POST', $path, $tok, $body, $ts, $secret),
        'X-PARTNER-ID'  => 'BNI', 'X-EXTERNAL-ID' => isset($opt['ext']) ? $opt['ext'] : Snap_sign::externalId(), 'CHANNEL-ID' => '95231',
    ], $body);
};
$base = ['partnerServiceId' => '   98829', 'customerNo' => $va['customer_no'], 'virtualAccountNo' => $va['va_no']];

list($http, $r) = $call('/v1.0/transfer-va/inquiry', $base + ['inquiryRequestId' => 'INQ1']);
check('inquiry tagihan (2002400, nominal benar)', $http === 200 && $r['responseCode'] === '2002400' && $r['virtualAccountData']['totalAmount']['value'] === '500000.00', json_encode($r));
list($http, $r) = $call('/v1.0/transfer-va/inquiry', ['customerNo' => '99999999', 'partnerServiceId' => '   98829', 'virtualAccountNo' => '   9882999999999', 'inquiryRequestId' => 'X']);
check('inquiry VA tak dikenal (4042412)', $http === 404 && $r['responseCode'] === '4042412', json_encode($r));
list($http, $r) = $call('/v1.0/transfer-va/payment', $base + ['paymentRequestId' => 'P1', 'paidAmount' => ['value' => '400000.00', 'currency' => 'IDR']]);
check('payment nominal salah ditolak (4042513)', $http === 404 && $r['responseCode'] === '4042513', json_encode($r));
list($http, $r) = $call('/v1.0/transfer-va/payment', $base + ['paymentRequestId' => 'P1', 'paidAmount' => ['value' => '500000.00', 'currency' => 'IDR']], ['secret' => 'salah']);
check('payment signature salah ditolak (4012500)', $http === 401 && $r['responseCode'] === '4012500', json_encode($r));
list($http, $r) = $call('/v1.0/transfer-va/payment', $base + ['paymentRequestId' => 'P1', 'paidAmount' => ['value' => '500000.00', 'currency' => 'IDR']], ['token' => 'palsu']);
check('payment token palsu ditolak (4012501)', $http === 401 && $r['responseCode'] === '4012501', json_encode($r));
list($http, $r) = $call('/v1.0/transfer-va/payment', $base + ['paidAmount' => ['value' => '500000.00', 'currency' => 'IDR']]);
check('payment tanpa paymentRequestId (4002502)', $http === 400 && $r['responseCode'] === '4002502', json_encode($r));

$ext = Snap_sign::externalId();
list($http, $r) = $call('/v1.0/transfer-va/payment', $base + ['paymentRequestId' => 'P1', 'paidAmount' => ['value' => '500000.00', 'currency' => 'IDR']], ['ext' => $ext]);
$row = $store->getVa($va['id']);
check('payment sukses (2002500) -> paid + diteruskan', $http === 200 && $r['responseCode'] === '2002500' && $row['status'] === 'paid' && $dispatched === [$va['id']] && $row['dispatched_at'], json_encode($r));
list($http, $r) = $call('/v1.0/transfer-va/payment', $base + ['paymentRequestId' => 'P1', 'paidAmount' => ['value' => '500000.00', 'currency' => 'IDR']], ['ext' => $ext]);
check('X-EXTERNAL-ID duplikat ditolak (4092500)', $http === 409 && $r['responseCode'] === '4092500', json_encode($r));
list($http, $r) = $call('/v1.0/transfer-va/payment', $base + ['paymentRequestId' => 'P1', 'paidAmount' => ['value' => '500000.00', 'currency' => 'IDR']]);
check('notifikasi ulang (paymentRequestId sama) idempoten, tak di-dispatch 2x', $http === 200 && count($dispatched) === 1, json_encode($r));
list($http, $r) = $call('/v1.0/transfer-va/payment', $base + ['paymentRequestId' => 'P2', 'paidAmount' => ['value' => '500000.00', 'currency' => 'IDR']]);
check('pembayaran kedua (paymentRequestId lain) -> Paid Bill (4042514)', $http === 404 && $r['responseCode'] === '4042514', json_encode($r));

// VA kedaluwarsa
$old = $store->insertVa(['profile' => 'test', 'purpose' => 'test', 'tenant_db' => null, 'ref' => 'EXP-' . getmypid(),
    'partner_service_id' => '   98829', 'va_name' => 'Kedaluwarsa', 'amount' => 1000, 'currency' => 'IDR',
    'expired_at' => date('Y-m-d H:i:s', time() - 60), 'trx_prefix' => 'E' . getmypid() . '-'], 8);
$store->setStatus($old['id'], 'pending');
list($http, $r) = $call('/v1.0/transfer-va/payment', ['partnerServiceId' => '   98829', 'customerNo' => $old['customer_no'], 'virtualAccountNo' => $old['va_no'],
    'paymentRequestId' => 'P3', 'paidAmount' => ['value' => '1000.00', 'currency' => 'IDR']]);
check('VA kedaluwarsa ditolak (4042519)', $http === 404 && $r['responseCode'] === '4042519', json_encode($r));

// Handler gagal tidak menggagalkan jawaban ke bank
$cfg['handlers']['test'] = function () { throw new RuntimeException('DB tenant mati'); };
$svc2 = new Snap_service($cfg, $store);
$in = new Snap_inbound($cfg, $store, [$svc2, 'dispatch']);
$va3 = $store->insertVa(['profile' => 'test', 'purpose' => 'test', 'tenant_db' => null, 'ref' => 'H-' . getmypid(),
    'partner_service_id' => '   98829', 'va_name' => 'Handler Gagal', 'amount' => 2000, 'currency' => 'IDR',
    'expired_at' => null, 'trx_prefix' => 'H' . getmypid() . '-'], 8);
$store->setStatus($va3['id'], 'pending');
list($http, $r) = $call('/v1.0/transfer-va/payment', ['partnerServiceId' => '   98829', 'customerNo' => $va3['customer_no'], 'virtualAccountNo' => $va3['va_no'],
    'paymentRequestId' => 'P4', 'paidAmount' => ['value' => '2000.00', 'currency' => 'IDR']]);
$row = $store->getVa($va3['id']);
check('handler gagal: bank tetap dijawab sukses, antre redispatch', $http === 200 && $row['status'] === 'paid' && !$row['dispatched_at'] && $row['dispatch_error'] === 'DB tenant mati', json_encode($row['dispatch_error']));

// ------------------------------------------------------- 4. smoke HTTP
if ($appUrl) {
    echo "4. Smoke HTTP ke $appUrl\n";
    $ch = curl_init("$appUrl/v1.0/access-token/b2b");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"grantType":"client_credentials"}', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-TIMESTAMP: ' . Snap_sign::timestamp(), 'X-CLIENT-KEY: tidak-ada', 'X-SIGNATURE: eA==']]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $r = json_decode($raw, true);
    check('rute token aktif, lolos CSRF, menjawab JSON SNAP', $http === 401 && isset($r['responseCode']) && $r['responseCode'] === '4017300', "HTTP $http " . substr((string) $raw, 0, 200));
    $ch = curl_init("$appUrl/v1.0/transfer-va/payment");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $r = json_decode($raw, true);
    check('rute payment tanpa token -> 4012501', $http === 401 && isset($r['responseCode']) && $r['responseCode'] === '4012501', "HTTP $http " . substr((string) $raw, 0, 200));
}

test_cleanup($tmp);
test_done();
