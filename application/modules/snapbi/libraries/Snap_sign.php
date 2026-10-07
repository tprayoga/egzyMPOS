<?php
/**
 * SNAP BI — tanda tangan & hash sesuai standar ASPI.
 *
 *  Asimetris (token B2B)  : SHA256withRSA( clientKey + "|" + X-TIMESTAMP ), base64.
 *  Simetris (transaksi)   : HMAC-SHA512( METHOD:relativeUrl:accessToken:
 *                           lowercase(hex(sha256(minify(body)))):X-TIMESTAMP , clientSecret ), base64.
 *
 * Kelas murni (tanpa CodeIgniter) agar bisa dipakai controller, CLI, dan test.
 */
class Snap_sign
{
    const TZ = 'Asia/Jakarta';

    /** X-TIMESTAMP ISO-8601 dengan offset, mis. 2026-10-06T08:00:00+07:00 */
    public static function timestamp($unix = null)
    {
        $d = new DateTime('@' . ($unix === null ? time() : (int) $unix));
        $d->setTimezone(new DateTimeZone(self::TZ));
        return $d->format('Y-m-d\TH:i:sP');
    }

    /** Validasi format X-TIMESTAMP; mengembalikan unix time atau null. */
    public static function parseTimestamp($ts)
    {
        if (!is_string($ts) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,3})?([+-]\d{2}:\d{2}|Z)$/', $ts)) {
            return null;
        }
        $t = strtotime($ts);
        return $t === false ? null : $t;
    }

    /** X-EXTERNAL-ID: numerik, unik per hari. */
    public static function externalId()
    {
        $d = new DateTime('now', new DateTimeZone(self::TZ));
        return $d->format('ymdHis') . sprintf('%06d', random_int(0, 999999));
    }

    /** Buang whitespace di luar string JSON (minify tanpa decode/encode ulang). */
    public static function minify($json)
    {
        $json = (string) $json;
        $out = '';
        $inStr = false;
        $len = strlen($json);
        for ($i = 0; $i < $len; $i++) {
            $c = $json[$i];
            if ($inStr) {
                $out .= $c;
                if ($c === '\\' && $i + 1 < $len) {
                    $out .= $json[++$i];
                } elseif ($c === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($c === '"') {
                $inStr = true;
                $out .= $c;
            } elseif ($c !== ' ' && $c !== "\n" && $c !== "\r" && $c !== "\t") {
                $out .= $c;
            }
        }
        return $out;
    }

    public static function bodyHash($body)
    {
        return strtolower(hash('sha256', self::minify($body)));
    }

    public static function asymmetric($clientKey, $timestamp, $privateKeyPem)
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if (!$key) {
            throw new RuntimeException('Private key SNAP tidak valid');
        }
        if (!openssl_sign($clientKey . '|' . $timestamp, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Gagal membuat signature asimetris');
        }
        return base64_encode($sig);
    }

    public static function verifyAsymmetric($clientKey, $timestamp, $signatureB64, $publicKeyPem)
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        $sig = base64_decode((string) $signatureB64, true);
        if (!$key || $sig === false) {
            return false;
        }
        return openssl_verify($clientKey . '|' . $timestamp, $sig, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public static function stringToSign($method, $relativeUrl, $accessToken, $body, $timestamp)
    {
        return strtoupper($method) . ':' . $relativeUrl . ':' . $accessToken . ':' . self::bodyHash($body) . ':' . $timestamp;
    }

    public static function symmetric($method, $relativeUrl, $accessToken, $body, $timestamp, $secret)
    {
        $s = self::stringToSign($method, $relativeUrl, $accessToken, $body, $timestamp);
        return base64_encode(hash_hmac('sha512', $s, $secret, true));
    }

    public static function verifySymmetric($method, $relativeUrl, $accessToken, $body, $timestamp, $secret, $signatureB64)
    {
        $expected = self::symmetric($method, $relativeUrl, $accessToken, $body, $timestamp, $secret);
        return is_string($signatureB64) && hash_equals($expected, $signatureB64);
    }

    /** Nilai uang SNAP: string 2 desimal, mis. "150000.00". */
    public static function amount($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** Baca PEM dari isi langsung atau path file. */
    public static function pem($valueOrPath)
    {
        if (!$valueOrPath) return null;
        if (strpos($valueOrPath, '-----BEGIN') !== false) return $valueOrPath;
        return is_file($valueOrPath) ? file_get_contents($valueOrPath) : null;
    }
}
