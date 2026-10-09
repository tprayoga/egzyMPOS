<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Ikat sesi login ke database tempat ia dibuat.
 *
 * Cookie `tnt` memilih DB tenant dan hanya divalidasi "terdaftar sebagai tenant". Tanpa ikatan ini,
 * pengguna yang sudah login di WP A bisa mengganti cookie ke DB WP B dan membaca data WP B dengan
 * sesi yang sama (ditemukan saat UAT 9 Okt 2026). Kini:
 *  - bind()  (post_controller): sesi admin (isLogIn) / tamu (UserID) yang belum terikat dicatat DB-nya;
 *            pada request login, itu DB hasil perutean login.
 *  - check() (post_controller_constructor): bila DB aktif berbeda dengan DB terikat -> sesi diakhiri.
 */
class Egzy_tenant_guard
{
    private function loggedIn($CI)
    {
        return isset($CI->session) && ($CI->session->userdata('isLogIn') || $CI->session->userdata('UserID'));
    }

    public function check()
    {
        $CI =& get_instance();
        if (!$CI || !isset($CI->db) || !$this->loggedIn($CI)) return;
        $bound = $CI->session->userdata('egzy_db');
        if (!$bound || $bound === $CI->db->database) return;
        log_message('error', "Egzy_tenant_guard: sesi terikat ke $bound dipakai pada " . $CI->db->database . ' dari ' . $CI->input->ip_address());
        $CI->session->sess_destroy();
        setcookie('tnt', '', ['expires' => time() - 3600, 'path' => '/']);
        if ($CI->input->is_ajax_request()) {
            show_error('Sesi tidak berlaku untuk data ini. Silakan login ulang.', 403);
        }
        redirect('login');
    }

    public function bind()
    {
        $CI =& get_instance();
        if (!$CI || !isset($CI->db) || !$this->loggedIn($CI)) return;
        if (!$CI->session->userdata('egzy_db')) {
            $CI->session->set_userdata('egzy_db', $CI->db->database);
        }
    }
}
