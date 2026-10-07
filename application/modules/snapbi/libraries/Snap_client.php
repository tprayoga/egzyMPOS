<?php
require_once __DIR__ . '/Snap_sign.php';

/**
 * SNAP BI — klien keluar (aplikasi -> bank) untuk layanan Transfer VA.
 *
 * Satu instance = satu profil kredensial (mis. 'bapenda', atau rekening hotel).
 * Path endpoint dapat ditimpa per profil (`paths`) bila dokumen teknis BNI
 * memakai prefiks berbeda dari standar ASPI.
 */
class Snap_client
{
    const DEFAULT_PATHS = [
        'token'  => '/v1.0/access-token/b2b',
        'create' => '/v1.0/transfer-va/create-va',
        'update' => '/v1.0/transfer-va/update-va',
        'status' => '/v1.0/transfer-va/status',
        'inquiry_va' => '/v1.0/transfer-va/inquiry-va',
        'delete' => '/v1.0/transfer-va/delete-va',
    ];

    private $name;
    private $p;
    private $store;
    /** @var callable|null untuk test: function($method,$url,$headers,$body) => [http, body] */
    private $transport;

    public function __construct($name, array $profile, Snap_store $store, callable $transport = null)
    {
        foreach (['base_url', 'client_id', 'client_secret', 'partner_id', 'channel_id', 'private_key'] as $k) {
            if (empty($profile[$k])) {
                throw new InvalidArgumentException("Profil SNAP '$name' belum lengkap: '$k' kosong");
            }
        }
        $this->name = $name;
        $this->p = $profile;
        $this->p['paths'] = array_replace(self::DEFAULT_PATHS, isset($profile['paths']) ? $profile['paths'] : []);
        $this->store = $store;
        $this->transport = $transport;
    }

    public function path($key)
    {
        return $this->p['paths'][$key];
    }

    public function accessToken($force = false)
    {
        if (!$force && ($t = $this->store->getOutboundToken($this->name))) {
            return $t;
        }
        $ts = Snap_sign::timestamp();
        $pem = Snap_sign::pem($this->p['private_key']);
        if (!$pem) {
            throw new RuntimeException("Private key profil '{$this->name}' tidak ditemukan");
        }
        $headers = [
            'Content-Type'  => 'application/json',
            'X-TIMESTAMP'   => $ts,
            'X-CLIENT-KEY'  => $this->p['client_id'],
            'X-SIGNATURE'   => Snap_sign::asymmetric($this->p['client_id'], $ts, $pem),
        ];
        $body = json_encode(['grantType' => 'client_credentials']);
        list($http, $res) = $this->send('POST', $this->path('token'), $headers, $body, null);

        if ($http !== 200 || empty($res['accessToken'])) {
            throw new RuntimeException('Token B2B ditolak: ' . (isset($res['responseCode']) ? $res['responseCode'] . ' ' . $res['responseMessage'] : "HTTP $http"));
        }
        $ttl = isset($res['expiresIn']) ? (int) $res['expiresIn'] : 900;
        $this->store->saveOutboundToken($this->name, $res['accessToken'], $ttl);
        return $res['accessToken'];
    }

    /**
     * Panggilan transaksional bertanda tangan simetris.
     * Mengembalikan [httpCode, responseArray]. Token diperbarui sekali bila ditolak (401).
     */
    public function call($method, $pathKey, array $payload)
    {
        $path = $this->path($pathKey);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->accessToken($attempt > 0);
            $ts = Snap_sign::timestamp();
            $extId = Snap_sign::externalId();
            $headers = [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'X-TIMESTAMP'   => $ts,
                'X-SIGNATURE'   => Snap_sign::symmetric($method, $path, $token, $body, $ts, $this->p['client_secret']),
                'X-PARTNER-ID'  => $this->p['partner_id'],
                'X-EXTERNAL-ID' => $extId,
                'CHANNEL-ID'    => $this->p['channel_id'],
            ];
            list($http, $res) = $this->send($method, $path, $headers, $body, $extId);
            if ($http === 401 && $attempt === 0) {
                $this->store->clearOutboundToken($this->name);
                continue;
            }
            return [$http, $res];
        }
        return [$http, $res];
    }

    // ----------------------------------------------------- Transfer VA

    public function createVa(array $va)
    {
        $payload = [
            'partnerServiceId'      => $va['partner_service_id'],
            'customerNo'            => $va['customer_no'],
            'virtualAccountNo'      => $va['va_no'],
            'virtualAccountName'    => $va['va_name'],
            'trxId'                 => $va['trx_id'],
            'totalAmount'           => ['value' => Snap_sign::amount($va['amount']), 'currency' => $va['currency']],
            'virtualAccountTrxType' => isset($this->p['va_trx_type']) ? $this->p['va_trx_type'] : 'C',
        ];
        if (!empty($va['expired_at'])) {
            $payload['expiredDate'] = Snap_sign::timestamp(strtotime($va['expired_at']));
        }
        if (!empty($this->p['additional_info'])) {
            $payload['additionalInfo'] = $this->p['additional_info'];
        }
        return $this->call('POST', 'create', $payload);
    }

    public function inquiryStatus(array $va)
    {
        return $this->call('POST', 'status', [
            'partnerServiceId' => $va['partner_service_id'],
            'customerNo'       => $va['customer_no'],
            'virtualAccountNo' => $va['va_no'],
            'inquiryRequestId' => $va['trx_id'],
        ]);
    }

    public function deleteVa(array $va)
    {
        return $this->call('DELETE', 'delete', [
            'partnerServiceId' => $va['partner_service_id'],
            'customerNo'       => $va['customer_no'],
            'virtualAccountNo' => $va['va_no'],
            'trxId'            => $va['trx_id'],
        ]);
    }

    // ------------------------------------------------------- transport

    private function send($method, $path, array $headers, $body, $externalId)
    {
        $url = rtrim($this->p['base_url'], '/') . $path;
        if ($this->transport) {
            list($http, $raw) = call_user_func($this->transport, $method, $url, $headers, $body);
        } else {
            $h = [];
            foreach ($headers as $k => $v) $h[] = "$k: $v";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $h,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => isset($this->p['timeout']) ? (int) $this->p['timeout'] : 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $raw = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($raw === false) {
                $raw = json_encode(['responseCode' => null, 'responseMessage' => 'curl: ' . curl_error($ch)]);
            }
            curl_close($ch);
        }
        $res = json_decode((string) $raw, true);
        if (!is_array($res)) $res = ['responseCode' => null, 'responseMessage' => 'Respons bukan JSON', 'raw' => mb_substr((string) $raw, 0, 500)];

        $logHeaders = $headers;
        unset($logHeaders['Authorization'], $logHeaders['X-SIGNATURE']);
        $this->store->log('out', $this->name, "$method $path", $externalId, $http,
            isset($res['responseCode']) ? $res['responseCode'] : null,
            json_encode(['headers' => $logHeaders, 'body' => json_decode($body, true)], JSON_UNESCAPED_SLASHES),
            json_encode($res, JSON_UNESCAPED_SLASHES));
        return [$http, $res];
    }
}
