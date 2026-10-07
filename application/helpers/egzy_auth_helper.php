<?php
/**
 * Password & reset password (EGZY).
 *
 * - Hash baru: password_hash() (bcrypt). Hash md5 lama tetap diterima saat login lalu
 *   di-upgrade otomatis (egzy_password_needs_upgrade), jadi user lama tidak terkunci.
 * - Reset password: OTP 8 karakter dari CSPRNG, terikat ke sesi yang memintanya,
 *   maksimal 5 percobaan, berlaku 15 menit, dan new_password HANYA jalan setelah
 *   check_code sukses di sesi yang sama.
 *
 * Fungsi plain-PHP: dipakai controller CI maupun skrip CLI (provisioning).
 */
if (!function_exists('egzy_password_hash')) {

    function egzy_password_hash($plain)
    {
        return password_hash((string) $plain, PASSWORD_DEFAULT);
    }

    function egzy_password_check($plain, $stored)
    {
        $stored = (string) $stored;
        if ($stored === '') return false;
        if (preg_match('/^[a-f0-9]{32}$/i', $stored)) {
            return hash_equals(strtolower($stored), md5((string) $plain)); // hash md5 lama
        }
        return password_verify((string) $plain, $stored);
    }

    function egzy_password_needs_upgrade($stored)
    {
        return preg_match('/^[a-f0-9]{32}$/i', (string) $stored) || password_needs_rehash((string) $stored, PASSWORD_DEFAULT);
    }

    /** Password acak (mis. untuk akun tamu walk-in / tenant baru). */
    function egzy_password_random($len = 12)
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
        return $out;
    }

    function egzy_password_policy_error($plain)
    {
        return strlen((string) $plain) < 8 ? 'Password minimal 8 karakter.' : null;
    }

    /**
     * Cocokkan password untuk baris yang memenuhi $where; mengembalikan id atau 0.
     * Hash md5 lama yang cocok langsung di-upgrade ke bcrypt.
     * PANGGIL SEBELUM membangun query lain: query builder CI berbagi state.
     */
    function egzy_login_check($db, $table, $idCol, $passCol, array $where, $plain)
    {
        if ((string) $plain === '') return 0;
        $rows = $db->select("$idCol AS id, $passCol AS pw")->from($table)->where($where)->get()->result();
        foreach ($rows as $r) {
            if (egzy_password_check($plain, $r->pw)) {
                if (egzy_password_needs_upgrade($r->pw)) {
                    $db->where($idCol, $r->id)->update($table, array($passCol => egzy_password_hash($plain)));
                }
                return (int) $r->id;
            }
        }
        return 0;
    }

    // ------------------------------------------------------- reset password

    define('EGZY_RESET_TTL', 900);
    define('EGZY_RESET_MAX_TRIES', 5);

    function egzy_reset_token()
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 8; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
        return $out;
    }

    /** Mulai reset di sesi ini (dipanggil forgot_check setelah token dikirim). */
    function egzy_reset_begin($session, $scope, $email, $token)
    {
        $session->set_userdata('pwreset_' . $scope, [
            'email' => strtolower(trim((string) $email)), 'token' => $token,
            'exp' => time() + EGZY_RESET_TTL, 'tries' => 0, 'verified' => false,
        ]);
    }

    /**
     * Cek OTP. Mengembalikan [ok(bool), pesan]. Kegagalan dihitung; setelah batas, reset dibatalkan.
     */
    function egzy_reset_verify($session, $scope, $email, $code)
    {
        $key = 'pwreset_' . $scope;
        $r = $session->userdata($key);
        if (!is_array($r) || $r['email'] !== strtolower(trim((string) $email)) || $r['exp'] < time()) {
            $session->unset_userdata($key);
            return [false, 'Sesi reset tidak valid atau kedaluwarsa. Minta kode baru.'];
        }
        if ($r['tries'] >= EGZY_RESET_MAX_TRIES) {
            $session->unset_userdata($key);
            return [false, 'Terlalu banyak percobaan. Minta kode baru.'];
        }
        if (!hash_equals($r['token'], strtoupper(trim((string) $code)))) {
            $r['tries']++;
            $session->set_userdata($key, $r);
            return [false, 'OTP Code Does Not Match'];
        }
        $r['verified'] = true;
        $session->set_userdata($key, $r);
        return [true, 'Please Enter New Password'];
    }

    /** Token yang sudah terverifikasi di sesi ini untuk email tsb, atau null. */
    function egzy_reset_verified_token($session, $scope, $email)
    {
        $r = $session->userdata('pwreset_' . $scope);
        if (is_array($r) && $r['verified'] && $r['exp'] >= time() && $r['email'] === strtolower(trim((string) $email))) {
            return $r['token'];
        }
        return null;
    }

    function egzy_reset_end($session, $scope)
    {
        $session->unset_userdata('pwreset_' . $scope);
    }
}
