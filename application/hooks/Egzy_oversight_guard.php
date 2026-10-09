<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Instance Bapenda (DB oversight) hanya untuk pengawasan PBJT.
 *
 * Modul operasional (reservasi, restoran, HR, stok, ...) di instance ini hanya berisi data
 * DB oversight sendiri — bukan data WP — dan tidak boleh dipakai Bapenda untuk mengubah data
 * operasional. Sidebar menyembunyikannya; hook ini menegakkannya di server untuk akses URL langsung.
 * Hanya berlaku bagi sesi login admin (isLogIn); callback bank (snapbi) & website publik tak tersentuh.
 */
class Egzy_oversight_guard
{
    /** Modul yang tetap boleh diakses Bapenda. dashboard = login, profil, user/role, pengaturan. */
    private $allowed = ['pbjt', 'dashboard', 'snapbi'];

    public function guard()
    {
        $CI =& get_instance();
        if (!$CI || !isset($CI->session) || !$CI->session->userdata('isLogIn')) return;
        if (!function_exists('egzy_is_oversight') || !egzy_is_oversight()) return;

        $module = method_exists($CI->router, 'fetch_module') ? (string) $CI->router->fetch_module() : '';
        $class  = strtolower($CI->router->fetch_class());
        $method = strtolower($CI->router->fetch_method());

        if ($module === 'dashboard' && $class === 'home' && $method === 'index') {
            redirect('pbjt'); // beranda Bapenda = Pengawasan PBJT
        }
        if ($module === '' || in_array($module, $this->allowed, true)) return;

        if ($CI->input->is_ajax_request()) {
            show_error('Menu operasional tidak tersedia untuk akun Bapenda.', 403);
        }
        $CI->session->set_flashdata('exception', 'Menu operasional hotel/restoran tidak tersedia untuk akun Bapenda.');
        redirect('pbjt');
    }
}
