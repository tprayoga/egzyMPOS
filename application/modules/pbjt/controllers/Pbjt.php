<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'modules/pbjt/libraries/Pbjt_engine.php';
require_once APPPATH . 'modules/pbjt/libraries/Pbjt_tagihan.php';
require_once APPPATH . 'modules/pbjt/libraries/Pbjt_stpd.php';
require_once APPPATH . 'modules/snapbi/libraries/Snap_service.php';

/**
 * Modul PBJT — pengawasan pajak, tenant-aware & instance-aware (silo).
 *
 * - Instance oversight (DB hotelv3): Bapenda melihat semua WP; kelola tenant.
 * - Instance tenant (DB tenant_*): halaman menampilkan status pajak WP pemilik
 *   instance itu saja. Konteks DIPAKSA "wp" sesuai registry — admin lokal tenant
 *   tidak pernah dianggap Bapenda. Data dibaca dari DB oversight.
 *
 * URL: /pbjt  /pbjt/reconcile  /pbjt/daftar  /pbjt/simpan  /pbjt/terbit_va  /pbjt/cek_va
 *      /pbjt/terbit_stpd  /pbjt/stpd/{id}
 */
class Pbjt extends MX_Controller
{
    private $engine;
    /** @var mysqli koneksi ke DB oversight (tempat tabel pbjt_*). */
    private $ov;
    /** wp_id bila halaman dibuka dari instance tenant; null bila instance oversight. */
    private $tenantWp = null;

    private $oversightDb = 'hotelv3';

    public function __construct()
    {
        parent::__construct();
        $__c = require APPPATH . 'config/pbjt_db.php';
        $this->oversightDb = $__c['oversight_db'];

        // Kenali instance: apakah DB aktif terdaftar sebagai DB tenant?
        $current = $this->db->database;
        $ctrl = $this->load->database('control', TRUE);
        $row = $ctrl->select('wp_id')->where('db_name', $current)->get('tenants')->row();
        if ($row) {
            $this->tenantWp = $row->wp_id;
        }

        if ($current === $this->oversightDb) {
            $this->ov = $this->db->conn_id;
        } else {
            $this->ov = @new mysqli($this->db->hostname, $this->db->username, $this->db->password, $this->oversightDb);
        }

        $this->engine = new Pbjt_engine($this->ov, [
            'porsi_nontunai_default' => 0.60,
            // tarif PBJT: dari config Pemda (config/pbjt.php), bukan di-hardcode di sini
            'ambang_skor'            => 50,
        ]);
    }

    private function ctx()
    {
        if (!$this->session->userdata('isLogIn')) {
            redirect('login');
            exit;
        }
        $userId = $this->session->userdata('id');
        if ($this->tenantWp !== null) {
            // Instance tenant: selalu WP pemilik instance, apa pun is_admin lokalnya.
            return ['role' => 'wp', 'wp_id' => $this->tenantWp, 'user_id' => (int) $userId];
        }
        return $this->engine->contextFromSession($userId, $this->session->userdata('user_type'));
    }

    /** Pesan informasi/akses-ditolak di dalam layout aplikasi. */
    private function info($title, $message, $icon = 'lock')
    {
        echo Modules::run('template/layout', [
            'title'   => $title,
            'module'  => 'pbjt',
            'page'    => 'info',
            'infoTitle' => $title,
            'infoMsg'   => $message,
            'infoIcon'  => $icon,
        ]);
    }

    private function audit($ctx, $aksi, $detail)
    {
        $st = $this->ov->prepare("INSERT INTO pbjt_audit_log (waktu,aktor,aksi,detail) VALUES (?,?,?,?)");
        if (!$st) return;
        $now = date('Y-m-d H:i:s');
        $aktor = $ctx['role'] . '#' . $ctx['user_id'] . ($ctx['wp_id'] ? '@' . $ctx['wp_id'] : '');
        $st->bind_param('ssss', $now, $aktor, $aksi, $detail);
        $st->execute();
        $st->close();
    }

    public function index()
    {
        $ctx = $this->ctx();
        if ($ctx['role'] === 'none') {
            return $this->info('Akses ditolak', 'Akun Anda belum ditautkan ke wajib pajak mana pun. Hubungi Bapenda untuk penautan akun.');
        }

        $masa = $this->input->get('masa', TRUE);
        if (!$masa) $masa = '2026-08';

        // READ-ONLY: sinkronisasi dipicu hook saat transaksi tersimpan.
        // Fallback: bila belum ada hasil sama sekali, hitung sekali.
        if (!$this->engine->masaTersedia()) {
            $this->engine->refreshLiveAll();
        }
        $masaList = $this->engine->masaTersedia();
        if (!$masaList) $masaList = [$masa];
        if (!in_array($masa, $masaList, TRUE)) $masa = $masaList[0];

        $this->audit($ctx, 'lihat-dashboard', "masa $masa");

        echo Modules::run('template/layout', [
            'title'    => 'Pengawasan PBJT',
            'module'   => 'pbjt',
            'page'     => 'dashboard',
            'ctx'      => $ctx,
            'masa'     => $masa,
            'masaList' => $masaList,
            'kpi'      => $this->engine->kpi($ctx, $masa),
            'temuan'   => $this->engine->temuan($ctx, $masa),
            'perKec'   => $this->engine->perKecamatan($ctx, $masa),
            'unmapped' => $this->engine->unmapped($ctx, $masa),
            'tagihan'  => $tagihan = $this->engine->tagihan($ctx, $masa),
            'stpd'     => (new Pbjt_stpd($this->ov))->terakhirPerTagihan(array_column($tagihan, 'tagihan_id')),
            'masaSelesai' => Pbjt_tagihan::masaSelesai($masa),
        ]);
    }

    /* ------------------------------------------------------------------
     * TAGIHAN PBJT via VA BNI (SNAP BI)
     * ---------------------------------------------------------------- */

    /** [Snap_service, Pbjt_tagihan, nama profil] */
    private function billing()
    {
        $cfg = require APPPATH . 'config/snap_bi.php';
        $svc = new Snap_service($cfg, Snap_store::connect());
        $tag = new Pbjt_tagihan($this->ov, [
            'tanggal_jatuh_tempo'     => $cfg['pbjt_tanggal_jatuh_tempo'],
            'masa_aktif_va_terlambat' => $cfg['pbjt_masa_aktif_va_terlambat'],
        ]);
        return [$svc, $tag, $cfg['pbjt_profile']];
    }

    private function aktor($ctx)
    {
        return $ctx['role'] . '#' . $ctx['user_id'];
    }

    /** POST: terbitkan VA untuk satu WP (wp_id) atau semua WP terutang (wp_id kosong). */
    public function terbit_va()
    {
        $ctx = $this->ctx();
        if ($ctx['role'] !== 'bapenda') {
            return $this->info('Khusus Bapenda', 'Penerbitan tagihan hanya dapat dilakukan Bapenda.');
        }
        $masa = (string) $this->input->post('masa', TRUE);
        $wp = (string) $this->input->post('wp_id', TRUE);
        if ($this->input->method() !== 'post') {
            redirect('pbjt?masa=' . urlencode($masa));
        }
        $ok = []; $gagal = [];
        try {
            list($svc, $tag, $profile) = $this->billing();
            $targets = $wp !== '' ? [$wp] : array_column(array_filter($this->engine->tagihan($ctx, $masa), function ($r) {
                return (int) $r['pajak_terutang'] > 0 && $r['status'] !== 'lunas';
            }), 'wp_id');
            foreach ($targets as $w) {
                try {
                    list($t, $va) = $tag->terbitkan($w, $masa, $svc, $profile, $this->aktor($ctx));
                    if ($va['status'] === 'pending') $ok[] = "$w (VA " . trim($va['va_no']) . ')';
                    else $gagal[] = "$w: bank menolak pembuatan VA";
                } catch (Throwable $e) {
                    $gagal[] = "$w: " . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $gagal[] = $e->getMessage();
        }
        if ($ok) $this->session->set_flashdata('message', 'VA terbit: ' . implode(', ', $ok));
        if ($gagal) $this->session->set_flashdata('exception', 'Gagal: ' . implode(' | ', $gagal));
        if (!$ok && !$gagal) $this->session->set_flashdata('message', 'Tidak ada tagihan yang perlu diterbitkan.');
        redirect('pbjt?masa=' . urlencode($masa));
    }

    /** POST: tanya status VA ke bank (Bapenda, atau WP untuk tagihannya sendiri). */
    public function cek_va()
    {
        $ctx = $this->ctx();
        $masa = (string) $this->input->post('masa', TRUE);
        $id = (int) $this->input->post('tagihan_id', TRUE);
        if ($this->input->method() !== 'post' || !$this->engine->tagihanDalamScope($ctx, $id)) {
            return $this->info('Akses ditolak', 'Tagihan tidak ditemukan atau bukan milik Anda.');
        }
        try {
            list($svc, $tag) = $this->billing();
            $t = $tag->get($id);
            if (!$t['snap_va_id']) throw new RuntimeException('Tagihan belum memiliki VA aktif.');
            list($http, $res, $va) = $svc->checkStatus($t['snap_va_id']);
            $t = $tag->get($id);
            $this->audit($ctx, 'cek-status-va', "tagihan #$id: {$va['status']}");
            $this->session->set_flashdata('message', $t['status'] === 'lunas'
                ? 'Tagihan sudah LUNAS.'
                : 'Belum ada pembayaran tercatat di bank (' . (isset($res['responseMessage']) ? $res['responseMessage'] : "HTTP $http") . ').');
        } catch (Throwable $e) {
            $this->session->set_flashdata('exception', 'Cek status gagal: ' . $e->getMessage());
        }
        redirect('pbjt?masa=' . urlencode($masa));
    }

    /* ------------------------------------------------------------------
     * STPD — bunga keterlambatan (parameter sanksi di pbjt_sanksi_config)
     * ---------------------------------------------------------------- */

    /** POST: terbitkan STPD untuk tagihan yang terlambat (Bapenda). */
    public function terbit_stpd()
    {
        $ctx = $this->ctx();
        if ($ctx['role'] !== 'bapenda') {
            return $this->info('Khusus Bapenda', 'Penerbitan STPD hanya dapat dilakukan Bapenda.');
        }
        $masa = (string) $this->input->post('masa', TRUE);
        $id = (int) $this->input->post('tagihan_id', TRUE);
        if ($this->input->method() !== 'post' || !$this->engine->tagihanDalamScope($ctx, $id)) {
            redirect('pbjt?masa=' . urlencode($masa));
        }
        try {
            $sid = (new Pbjt_stpd($this->ov))->terbitkan($id, $this->aktor($ctx));
            $this->audit($ctx, 'terbit-stpd', "tagihan #$id -> STPD #$sid");
            redirect('pbjt/stpd/' . $sid);
        } catch (Throwable $e) {
            $this->session->set_flashdata('exception', 'STPD gagal diterbitkan: ' . $e->getMessage());
            redirect('pbjt?masa=' . urlencode($masa));
        }
    }

    /** GET: dokumen STPD siap cetak (Bapenda, atau WP pemilik). */
    public function stpd($id = 0)
    {
        $ctx = $this->ctx();
        $s = (new Pbjt_stpd($this->ov))->get((int) $id);
        if (!$s || ($ctx['role'] !== 'bapenda' && !($ctx['role'] === 'wp' && $ctx['wp_id'] === $s['wp_id']))) {
            return $this->info('Akses ditolak', 'STPD tidak ditemukan atau bukan milik Anda.');
        }
        $this->audit($ctx, 'lihat-stpd', "STPD #{$s['id']} {$s['wp_id']} masa {$s['masa']}");
        $this->load->view('pbjt/stpd_dokumen', ['s' => $s, 'cfg' => json_decode($s['config_snapshot'], true), 'alamat' => $this->_alamatWp($s['wp_id'])]);
    }

    /** Alamat & telepon WP dari DB tenant-nya (bila WP memakai aplikasi). */
    private function _alamatWp($wp)
    {
        $ctrl = $this->load->database('control', TRUE);
        $t = $ctrl->select('db_name')->where('wp_id', $wp)->get('tenants')->row();
        if (!$t) return null;
        $db = str_replace('`', '', $t->db_name);
        $r = $this->ov->query("SELECT address, phone FROM `$db`.setting LIMIT 1");
        return $r ? $r->fetch_assoc() : null;
    }

    public function reconcile()
    {
        $ctx = $this->ctx();
        if ($ctx['role'] !== 'bapenda') {
            return $this->info('Khusus Bapenda', 'Rekonsiliasi hanya dapat dijalankan oleh Bapenda dari instance pengawasan.');
        }
        $masa = $this->input->get('masa', TRUE) ?: '2026-08';
        $n = $this->engine->refreshLive($masa);
        $this->audit($ctx, 'rekonsiliasi', "masa $masa: $n temuan");
        $this->session->set_flashdata('message', "Rekonsiliasi masa $masa selesai: $n temuan.");
        redirect('pbjt?masa=' . urlencode($masa));
    }

    /** Halaman kelola tenant (Bapenda): daftar WP + form provisioning. */
    public function daftar()
    {
        $ctx = $this->ctx();
        if ($ctx['role'] !== 'bapenda') {
            return $this->info('Khusus Bapenda', 'Pengelolaan tenant hanya tersedia bagi Bapenda.');
        }
        $ctrl = $this->load->database('control', TRUE);
        echo Modules::run('template/layout', [
            'title'   => 'Kelola Tenant',
            'module'  => 'pbjt',
            'page'    => 'tenant_admin',
            'tenants' => $ctrl->order_by('wp_id')->get('tenants')->result(),
        ]);
    }

    /** Proses provisioning WP baru (Bapenda). */
    public function simpan()
    {
        $ctx = $this->ctx();
        if ($ctx['role'] !== 'bapenda') {
            return $this->info('Khusus Bapenda', 'Pengelolaan tenant hanya tersedia bagi Bapenda.');
        }
        require_once APPPATH . 'modules/pbjt/libraries/Pbjt_provision.php';
        $wp    = $this->input->post('wp_id', TRUE);
        $nama  = $this->input->post('nama', TRUE);
        $jenis = $this->input->post('jenis', TRUE);
        $kec   = $this->input->post('kecamatan', TRUE);
        $email = $this->input->post('email', TRUE);
        $pass  = (string) $this->input->post('password', FALSE);
        if ($pass === '') {
            $pass = egzy_password_random(); // sementara; ditampilkan sekali untuk diserahkan ke WP
        } elseif ($err = egzy_password_policy_error($pass)) {
            $this->session->set_flashdata('exception', 'Gagal: ' . $err);
            redirect('pbjt/daftar');
        }
        try {
            $prov = new Pbjt_provision($this->db->conn_id);
            $db = $prov->createTenant($wp, $nama, $jenis, $kec, $email, $pass);
            $this->audit($ctx, 'provision-tenant', "$wp -> $db");
            $this->session->set_flashdata('message', "Tenant $wp ($nama) dibuat pada DB `$db`. Login: $email / $pass");
        } catch (\Throwable $e) {
            $this->session->set_flashdata('exception', 'Gagal: ' . $e->getMessage());
        }
        redirect('pbjt/daftar');
    }
}
