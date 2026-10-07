<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pemilih tenant (SEMENTARA, untuk demo silo multi-tenant).
 * Mengatur cookie 'tnt' sehingga seluruh aplikasi berjalan pada DB tenant tsb.
 *
 * PRODUKSI: penentuan tenant TIDAK lewat URL seperti ini, melainkan otomatis
 * dari direktori login (pbjt_control.directory) saat user memasukkan email.
 * Controller ini hanya alat peraga dan harus dihapus/diproteksi di produksi.
 *
 *   /tenant/open/tenant_wp002  -> pindah ke instance WP002, lalu ke halaman login
 *   /tenant/clear              -> kembali ke DB default
 */
class Tenant extends CI_Controller
{
    public function open($db = '')
    {
        $db = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $db);
        $valid = false;
        if ($db !== '') {
            $__c = require APPPATH . 'config/pbjt_db.php';
            $ctrl = @new mysqli($__c['host'], $__c['user'], $__c['pass'], $__c['control_db']);
            if ($ctrl && !$ctrl->connect_errno) {
                $q = $ctrl->query("SELECT 1 FROM tenants WHERE db_name='" . $ctrl->real_escape_string($db) . "' AND status=1 LIMIT 1");
                $valid = $q && $q->num_rows;
                $ctrl->close();
            }
        }
        if ($valid) {
            setcookie('tnt', $db, ['expires' => 0, 'path' => '/', 'samesite' => 'Lax', 'secure' => is_https(), 'httponly' => true]);
        } else {
            setcookie('tnt', '', ['expires' => time() - 3600, 'path' => '/']);
        }
        // Bersihkan sesi agar tidak membawa login dari DB sebelumnya.
        $this->load->library('session');
        $this->session->sess_destroy();
        redirect('login');
    }

    public function clear()
    {
        setcookie('tnt', '', ['expires' => time() - 3600, 'path' => '/']);
        $this->load->library('session');
        $this->session->sess_destroy();
        redirect('login');
    }
}
