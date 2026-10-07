<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Hook PBJT — dipicu SAAT TRANSAKSI TERSIMPAN.
 *
 * Terdaftar sebagai post_controller. Hanya bertindak jika controller/method
 * yang baru dijalankan adalah aksi penyimpanan transaksi (booking kamar / order resto).
 * Saat cocok, tarik omzet terbaru dari aplikasi lalu rekonsiliasi ulang, sehingga
 * /pbjt selalu mutakhir tanpa menghitung ulang di setiap pembukaan halaman.
 */
class Pbjt_sync_hook
{
    /** Aksi penyimpanan transaksi yang dipantau: controller => [method,...] (lowercase). */
    private $watch = [
        'room_reservation' => ['newbooking', 'checkinbooking', 'submitcheckout', 'bookingcheckout', 'checkoutall', 'directcheckin'],
        'order'            => ['insert_customerord', 'pos_invoice'],
    ];

    public function sync()
    {
        $CI =& get_instance();
        if (!$CI || !isset($CI->router)) return;

        $class  = strtolower($CI->router->fetch_class());
        $method = strtolower($CI->router->fetch_method());

        if (!isset($this->watch[$class]) || !in_array($method, $this->watch[$class], true)) {
            return; // bukan aksi transaksi -> lewati
        }

        try {
            if (!isset($CI->db) || !is_object($CI->db)) {
                $CI->load->database();
            }
            require_once APPPATH . 'modules/pbjt/libraries/Pbjt_engine.php';
            // SILO: engine oversight SELALU memakai DB oversight (hotelv3), bukan DB tenant
            // dari request ini. Omzet ditarik dari DB tiap tenant via syncOmzetFromTenants().
            $__c = require APPPATH . 'config/pbjt_db.php';
            $oversight = @new mysqli($__c['host'], $__c['user'], $__c['pass'], $__c['oversight_db']);
            if (!$oversight || $oversight->connect_errno) return;
            $engine = new Pbjt_engine($oversight);
            $engine->refreshLiveAll();
            $oversight->close();
        } catch (\Throwable $e) {
            // Jangan pernah mengganggu alur transaksi aplikasi bila sinkronisasi gagal.
            log_message('error', 'PBJT sync hook gagal: ' . $e->getMessage());
        }
    }
}
