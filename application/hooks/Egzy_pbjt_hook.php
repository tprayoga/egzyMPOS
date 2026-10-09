<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PBJT per transaksi di aplikasi tenant: setelah aksi POST modul restoran/reservasi, hitung dpp/pbjt
 * lewat engine PBJT (Pbjt_pajak). Bill restoran yang BELUM dibayar diselaraskan ke PBJT engine
 * (VAT & total), sehingga PBJT hanya berasal dari engine apa pun jalur POS-nya (order baru, ubah
 * order, gabung, pisah). Bill yang sudah dibayar tidak diubah nominalnya.
 */
class Egzy_pbjt_hook
{
    public function sync()
    {
        $CI =& get_instance();
        if (!$CI || !isset($CI->router, $CI->db) || $CI->input->method() !== 'post') return;
        $module = method_exists($CI->router, 'fetch_module') ? (string) $CI->router->fetch_module() : '';
        if (!in_array($module, ['ordermanage', 'room_reservation'], true)) return;
        if (!($CI->db->conn_id instanceof mysqli)) return;
        try {
            require_once APPPATH . 'modules/pbjt/libraries/Pbjt_pajak.php';
            $tarif = Pbjt_pajak::tarif();
            if ($module === 'ordermanage') {
                Pbjt_pajak::sinkronResto($CI->db->conn_id, $CI->db->database, $tarif, true);
            } else {
                Pbjt_pajak::sinkronKamar($CI->db->conn_id, $CI->db->database, $tarif);
            }
        } catch (Throwable $e) {
            log_message('error', 'Egzy_pbjt_hook: ' . $e->getMessage());
        }
    }
}
