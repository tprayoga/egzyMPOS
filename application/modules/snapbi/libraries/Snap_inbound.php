<?php
require_once __DIR__ . '/Snap_sign.php';

/**
 * SNAP BI — endpoint masuk (bank -> aplikasi).
 *
 *   POST {base}/v1.0/access-token/b2b     token B2B untuk bank (signature asimetris)
 *   POST {base}/v1.0/transfer-va/inquiry  inquiry tagihan VA     (layanan 24)
 *   POST {base}/v1.0/transfer-va/payment  notifikasi pembayaran  (layanan 25)
 *
 * handle() murni: menerima method/path/header/body, mengembalikan [http, array].
 * responseCode = HTTP(3) + kode layanan(2) + kode kasus(2), sesuai SNAP BI.
 */
class Snap_inbound
{
    const SVC_TOKEN = '73';
    const SVC_INQUIRY = '24';
    const SVC_PAYMENT = '25';

    private $cfg;
    private $store;
    private $onPaid;

    public function __construct(array $cfg, Snap_store $store, callable $onPaid = null)
    {
        $this->cfg = $cfg;
        $this->store = $store;
        $this->onPaid = $onPaid;
    }

    public function handle($method, $path, array $headers, $body)
    {
        $h = [];
        foreach ($headers as $k => $v) $h[strtolower($k)] = is_array($v) ? reset($v) : $v;

        if ($this->endsWith($path, '/access-token/b2b')) {
            $svc = self::SVC_TOKEN;
            $profile = null;
            $res = $this->token($method, $h, $body, $profile);
        } elseif ($this->endsWith($path, '/transfer-va/inquiry')) {
            $svc = self::SVC_INQUIRY;
            $res = $this->transactional($svc, $method, $path, $h, $body, $profile, 'inquiry');
        } elseif ($this->endsWith($path, '/transfer-va/payment')) {
            $svc = self::SVC_PAYMENT;
            $res = $this->transactional($svc, $method, $path, $h, $body, $profile, 'payment');
        } else {
            $profile = null;
            $res = $this->err(404, '00', '00', 'Not Found');
        }

        $this->store->log('in', $profile, "$method $path", isset($h['x-external-id']) ? mb_substr($h['x-external-id'], 0, 36) : null,
            $res[0], isset($res[1]['responseCode']) ? $res[1]['responseCode'] : null,
            json_encode(['headers' => $this->safeHeaders($h), 'body' => json_decode((string) $body, true)], JSON_UNESCAPED_SLASHES),
            json_encode($res[1], JSON_UNESCAPED_SLASHES));
        return $res;
    }

    // ------------------------------------------------------------ token

    private function token($method, array $h, $body, &$profile)
    {
        $svc = self::SVC_TOKEN;
        if ($method !== 'POST') return $this->err(405, $svc, '00', 'Method Not Allowed');

        foreach (['x-timestamp', 'x-client-key', 'x-signature'] as $k) {
            if (empty($h[$k])) return $this->err(400, $svc, '02', 'Invalid Mandatory Field [' . strtoupper($k) . ']');
        }
        if (Snap_sign::parseTimestamp($h['x-timestamp']) === null) {
            return $this->err(400, $svc, '01', 'Invalid Field Format [X-TIMESTAMP]');
        }
        $profile = $this->profileByClientKey($h['x-client-key']);
        if ($profile === null) return $this->err(401, $svc, '00', 'Unauthorized. [Unknown client]');

        $in = $this->cfg['profiles'][$profile]['inbound'];
        $pub = Snap_sign::pem(isset($in['public_key']) ? $in['public_key'] : null);
        if (!$pub || !Snap_sign::verifyAsymmetric($h['x-client-key'], $h['x-timestamp'], $h['x-signature'], $pub)) {
            return $this->err(401, $svc, '00', 'Unauthorized. [Signature]');
        }

        $req = json_decode((string) $body, true);
        if (!is_array($req) || empty($req['grantType'])) return $this->err(400, $svc, '02', 'Invalid Mandatory Field [grantType]');
        if ($req['grantType'] !== 'client_credentials') return $this->err(400, $svc, '01', 'Invalid Field Format [grantType]');

        $ttl = isset($this->cfg['token_ttl']) ? (int) $this->cfg['token_ttl'] : 900;
        return [200, [
            'responseCode'    => '200' . $svc . '00',
            'responseMessage' => 'Successful',
            'accessToken'     => $this->store->issueInboundToken($profile, $ttl),
            'tokenType'       => 'Bearer',
            'expiresIn'       => (string) $ttl,
        ]];
    }

    // ---------------------------------------------------- transactional

    private function transactional($svc, $method, $path, array $h, $body, &$profile, $action)
    {
        $profile = null;
        if ($method !== 'POST') return $this->err(405, $svc, '00', 'Method Not Allowed');

        $auth = isset($h['authorization']) ? $h['authorization'] : '';
        $token = stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : '';
        $profile = $this->store->inboundTokenProfile($token);
        if ($profile === null) return $this->err(401, $svc, '01', 'Invalid Token (B2B)');

        foreach (['x-timestamp', 'x-signature', 'x-partner-id', 'x-external-id', 'channel-id'] as $k) {
            if (empty($h[$k])) return $this->err(400, $svc, '02', 'Invalid Mandatory Field [' . strtoupper($k) . ']');
        }
        if (Snap_sign::parseTimestamp($h['x-timestamp']) === null) {
            return $this->err(400, $svc, '01', 'Invalid Field Format [X-TIMESTAMP]');
        }
        $in = $this->cfg['profiles'][$profile]['inbound'];
        if (!Snap_sign::verifySymmetric($method, $path, $token, $body, $h['x-timestamp'], $in['client_secret'], $h['x-signature'])) {
            return $this->err(401, $svc, '00', 'Unauthorized. [Signature]');
        }
        if (!empty($in['partner_id']) && $h['x-partner-id'] !== $in['partner_id']) {
            return $this->err(401, $svc, '00', 'Unauthorized. [Unknown client]');
        }
        if (!preg_match('/^\d{1,36}$/', $h['x-external-id'])) {
            return $this->err(400, $svc, '01', 'Invalid Field Format [X-EXTERNAL-ID]');
        }
        if (!$this->store->claimExternalId($profile, $h['x-external-id'])) {
            return $this->err(409, $svc, '00', 'Conflict');
        }

        $req = json_decode((string) $body, true);
        if (!is_array($req)) return $this->err(400, $svc, '01', 'Invalid Field Format [body]');

        return $action === 'inquiry' ? $this->inquiry($svc, $req, $profile) : $this->payment($svc, $req, $profile);
    }

    private function findBill($svc, array $req, $profile)
    {
        $va = $this->store->findVa($req['partnerServiceId'], $req['customerNo']);
        if (!$va || $va['profile'] !== $profile || in_array($va['status'], ['draft', 'failed', 'deleted'], true)) {
            return [null, $this->err(404, $svc, '12', 'Invalid Bill/Virtual Account [Not Found]')];
        }
        $expired = $va['status'] === 'expired' || ($va['status'] === 'pending' && $va['expired_at'] && strtotime($va['expired_at']) < time());
        if ($expired) {
            if ($va['status'] === 'pending') $this->store->setStatus($va['id'], 'expired');
            return [null, $this->err(404, $svc, '19', 'Invalid Bill/Virtual Account [Expired]')];
        }
        return [$va, null];
    }

    private function inquiry($svc, array $req, $profile)
    {
        foreach (['partnerServiceId', 'customerNo', 'virtualAccountNo', 'inquiryRequestId'] as $k) {
            if (!isset($req[$k]) || $req[$k] === '') return $this->err(400, $svc, '02', "Invalid Mandatory Field [$k]");
        }
        list($va, $err) = $this->findBill($svc, $req, $profile);
        if ($err) return $err;
        if ($va['status'] === 'paid') return $this->err(404, $svc, '14', 'Paid Bill');

        return [200, [
            'responseCode'       => '200' . $svc . '00',
            'responseMessage'    => 'Successful',
            'virtualAccountData' => [
                'inquiryStatus'      => '00',
                'inquiryReason'      => ['english' => 'Success', 'indonesia' => 'Sukses'],
                'partnerServiceId'   => $va['partner_service_id'],
                'customerNo'         => $va['customer_no'],
                'virtualAccountNo'   => $va['va_no'],
                'virtualAccountName' => $va['va_name'],
                'inquiryRequestId'   => $req['inquiryRequestId'],
                'totalAmount'        => ['value' => Snap_sign::amount($va['amount']), 'currency' => $va['currency']],
                'virtualAccountTrxType' => isset($this->cfg['profiles'][$profile]['va_trx_type']) ? $this->cfg['profiles'][$profile]['va_trx_type'] : 'C',
            ],
        ]];
    }

    private function payment($svc, array $req, $profile)
    {
        foreach (['partnerServiceId', 'customerNo', 'virtualAccountNo', 'paymentRequestId', 'paidAmount'] as $k) {
            if (!isset($req[$k]) || $req[$k] === '') return $this->err(400, $svc, '02', "Invalid Mandatory Field [$k]");
        }
        if (!isset($req['paidAmount']['value']) || !is_numeric($req['paidAmount']['value'])) {
            return $this->err(400, $svc, '01', 'Invalid Field Format [paidAmount.value]');
        }
        $currency = isset($req['paidAmount']['currency']) ? $req['paidAmount']['currency'] : 'IDR';

        list($va, $err) = $this->findBill($svc, $req, $profile);
        if ($err) return $err;

        if ($va['status'] === 'paid') {
            // Pengulangan notifikasi yang sama oleh bank = idempoten, jawab sukses lagi.
            if ($va['payment_request_id'] === (string) $req['paymentRequestId']) {
                return $this->paymentOk($svc, $va, $req);
            }
            return $this->err(404, $svc, '14', 'Paid Bill');
        }
        if ($currency !== $va['currency'] || abs((float) $req['paidAmount']['value'] - (float) $va['amount']) > 0.001) {
            return $this->err(404, $svc, '13', 'Invalid Amount');
        }

        if ($this->store->markPaid($va['id'], $req['paidAmount']['value'], (string) $req['paymentRequestId'])) {
            $va = $this->store->getVa($va['id']);
            if ($this->onPaid) call_user_func($this->onPaid, $va);
        } else {
            // Kalah balapan dengan notifikasi lain: baca ulang dan putuskan.
            $va = $this->store->getVa($va['id']);
            if ($va['payment_request_id'] !== (string) $req['paymentRequestId']) {
                return $this->err(404, $svc, '14', 'Paid Bill');
            }
        }
        return $this->paymentOk($svc, $va, $req);
    }

    private function paymentOk($svc, array $va, array $req)
    {
        return [200, [
            'responseCode'       => '200' . $svc . '00',
            'responseMessage'    => 'Successful',
            'virtualAccountData' => [
                'paymentFlagStatus'  => '00',
                'paymentFlagReason'  => ['english' => 'Success', 'indonesia' => 'Sukses'],
                'partnerServiceId'   => $va['partner_service_id'],
                'customerNo'         => $va['customer_no'],
                'virtualAccountNo'   => $va['va_no'],
                'virtualAccountName' => $va['va_name'],
                'paymentRequestId'   => (string) $req['paymentRequestId'],
                'paidAmount'         => ['value' => Snap_sign::amount($va['paid_amount']), 'currency' => $va['currency']],
                'trxDateTime'        => isset($req['trxDateTime']) ? $req['trxDateTime'] : Snap_sign::timestamp(strtotime($va['paid_at'])),
            ],
        ]];
    }

    // ---------------------------------------------------------- helpers

    private function profileByClientKey($clientKey)
    {
        foreach ((array) $this->cfg['profiles'] as $name => $p) {
            if (!empty($p['inbound']['client_key']) && hash_equals((string) $p['inbound']['client_key'], (string) $clientKey)) {
                return $name;
            }
        }
        return null;
    }

    private function err($http, $svc, $case, $message)
    {
        return [$http, ['responseCode' => $http . $svc . $case, 'responseMessage' => $message]];
    }

    private function endsWith($s, $suffix)
    {
        return substr($s, -strlen($suffix)) === $suffix;
    }

    private function safeHeaders(array $h)
    {
        unset($h['authorization'], $h['x-signature'], $h['cookie']);
        return $h;
    }
}
