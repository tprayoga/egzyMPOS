<?php
/**
 * Bank tiruan SNAP BI untuk test lokal (router `php -S`).
 * Memverifikasi signature asimetris & simetris dari aplikasi, seperti bank sungguhan.
 *
 * Env: MOCK_CLIENT_ID, MOCK_CLIENT_SECRET, MOCK_PARTNER_PUB (path PEM), MOCK_STATE (path JSON)
 */
require __DIR__ . '/../../application/modules/snapbi/libraries/Snap_sign.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input');
$h = array_change_key_case(getallheaders(), CASE_LOWER);
$statePath = getenv('MOCK_STATE');
$state = is_file($statePath) ? json_decode(file_get_contents($statePath), true) : ['tokens' => [], 'va' => [], 'ext' => []];

function out($code, $arr)
{
    global $state, $statePath;
    file_put_contents($statePath, json_encode($state));
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
}

if ($path === '/v1.0/access-token/b2b') {
    if (($h['x-client-key'] ?? '') !== getenv('MOCK_CLIENT_ID')) out(401, ['responseCode' => '4017300', 'responseMessage' => 'Unauthorized. [Unknown client]']);
    $ok = Snap_sign::verifyAsymmetric($h['x-client-key'], $h['x-timestamp'] ?? '', $h['x-signature'] ?? '', file_get_contents(getenv('MOCK_PARTNER_PUB')));
    if (!$ok) out(401, ['responseCode' => '4017300', 'responseMessage' => 'Unauthorized. [Signature]']);
    $t = bin2hex(random_bytes(16));
    $state['tokens'][] = $t;
    out(200, ['responseCode' => '2007300', 'responseMessage' => 'Successful', 'accessToken' => $t, 'tokenType' => 'Bearer', 'expiresIn' => '900']);
}

// Transaksional
$token = substr($h['authorization'] ?? '', 7);
if (!in_array($token, $state['tokens'], true)) out(401, ['responseCode' => '4010001', 'responseMessage' => 'Invalid Token (B2B)']);
if (!Snap_sign::verifySymmetric($method, $path, $token, $body, $h['x-timestamp'] ?? '', getenv('MOCK_CLIENT_SECRET'), $h['x-signature'] ?? '')) {
    out(401, ['responseCode' => '4010000', 'responseMessage' => 'Unauthorized. [Signature]']);
}
foreach (['x-partner-id', 'x-external-id', 'channel-id'] as $k) {
    if (empty($h[$k])) out(400, ['responseCode' => '4000002', 'responseMessage' => "Invalid Mandatory Field [$k]"]);
}
if (isset($state['ext'][$h['x-external-id']])) out(409, ['responseCode' => '4090000', 'responseMessage' => 'Conflict']);
$state['ext'][$h['x-external-id']] = 1;
$req = json_decode($body, true);

switch ($path) {
    case '/v1.0/transfer-va/create-va':
        $state['va'][trim($req['virtualAccountNo'])] = $req + ['paid' => false];
        out(200, ['responseCode' => '2002700', 'responseMessage' => 'Successful', 'virtualAccountData' => $req]);
    case '/v1.0/transfer-va/status':
        $va = $state['va'][trim($req['virtualAccountNo'])] ?? null;
        if (!$va) out(404, ['responseCode' => '4042612', 'responseMessage' => 'Invalid Bill/Virtual Account']);
        out(200, ['responseCode' => '2002600', 'responseMessage' => 'Successful', 'virtualAccountData' => [
            'paymentFlagStatus' => $va['paid'] ? '00' : '01',
            'paidAmount' => $va['totalAmount'], 'paymentRequestId' => 'MOCK-' . $va['trxId'],
        ]]);
    case '/v1.0/transfer-va/delete-va':
        unset($state['va'][trim($req['virtualAccountNo'])]);
        out(200, ['responseCode' => '2003100', 'responseMessage' => 'Successful']);
    case '/__mark_paid':
        $state['va'][trim($req['virtualAccountNo'])]['paid'] = true;
        out(200, ['ok' => true]);
}
out(404, ['responseCode' => '4040000', 'responseMessage' => 'Not Found']);
