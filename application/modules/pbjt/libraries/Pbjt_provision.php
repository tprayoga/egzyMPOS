<?php
defined('BASEPATH') OR (defined('PBJT_CLI') OR exit('No direct script access allowed'));

/**
 * Pbjt_provision — provisioning tenant baru (silo).
 *
 * Membuat DB tenant dari TEMPLATE BERSIH (skema + konfigurasi, tanpa data transaksi),
 * memberi identitas & admin milik WP, lalu mendaftarkan ke registry/direktori (kontrol)
 * dan master WP oversight (Bapenda). Setelah ini WP langsung bisa login & beroperasi.
 */
require_once dirname(__DIR__, 3) . '/helpers/egzy_auth_helper.php';

class Pbjt_provision
{
    /** @var mysqli koneksi level server (boleh terpilih DB apa pun). */
    private $db;
    private $source    = 'hotelv3';         // sumber skema/konfigurasi
    private $template  = 'tenant_template';  // template bersih
    private $control   = 'pbjt_control';
    private $oversight = 'hotelv3';

    // Tabel transaksi yang DIKOSONGKAN di template (agar tenant baru mulai bersih).
    private $txTables = [
        'booked_info','booked_details','customer_order','sub_order','order_menu','bill',
        'multipay_bill','bill_card_payment','tbl_postedbills','tbl_hallroom_booking',
        'tbl_hallroom_postbill','tbl_guestpayments','tbl_kitchen_order','tbl_orderprepare',
        'tbl_vehicle_booking','tbl_bookParking','tbl_laundry_payment','customerinfo',
    ];

    public function __construct($db) { $this->db = $db; }

    private function tablesOf($schema, $excludePbjt = true)
    {
        $schema = $this->db->real_escape_string($schema);
        $out = [];
        $r = $this->db->query("SELECT table_name FROM information_schema.tables
            WHERE table_schema='$schema' AND table_type='BASE TABLE'");
        // $excludePbjt=true: buang tabel oversight (pbjt_*) saat membangun template dari hotelv3.
        // false: klon SEMUA (template sudah bersih; ini menjaga artefak migrasi ikut ke tenant baru).
        while ($x = $r->fetch_row()) { if (!$excludePbjt || strpos($x[0], 'pbjt_') !== 0) $out[] = $x[0]; }
        return $out;
    }

    /** Query yang WAJIB berhasil — lempar exception bila gagal (jangan pernah gagal diam-diam). */
    private function q($sql)
    {
        $r = $this->db->query($sql);
        if ($r === false) throw new \Exception("Query gagal: " . $this->db->error . " | " . substr($sql, 0, 120));
        return $r;
    }

    private function cloneDb($src, $dst, $truncate = [], $excludePbjt = true)
    {
        $tables = $this->tablesOf($src, $excludePbjt);
        if (!$tables) throw new \Exception("Sumber `$src` tidak punya tabel untuk diklon.");
        $this->q("DROP DATABASE IF EXISTS `$dst`");
        $this->q("CREATE DATABASE `$dst` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $this->q("SET FOREIGN_KEY_CHECKS=0");
        try {
            foreach ($tables as $tb) {
                $this->q("CREATE TABLE `$dst`.`$tb` LIKE `$src`.`$tb`");
                if (!in_array($tb, $truncate, true)) {
                    $this->q("INSERT INTO `$dst`.`$tb` SELECT * FROM `$src`.`$tb`");
                }
            }
        } finally {
            $this->db->query("SET FOREIGN_KEY_CHECKS=1");
        }
        $got = count($this->tablesOf($dst, false));
        if ($got !== count($tables)) throw new \Exception("Klon `$dst` tidak lengkap: $got dari " . count($tables) . " tabel.");
    }

    /** Buat template bersih sekali (skema+konfigurasi hotelv3, tabel transaksi dikosongkan). */
    public function ensureTemplate($force = false)
    {
        $exists = $this->db->query("SELECT 1 FROM information_schema.schemata WHERE schema_name='{$this->template}'");
        if (!$force && $exists && $exists->num_rows) return false;
        $this->cloneDb($this->source, $this->template, $this->txTables);
        return true;
    }

    private function esc($s) { return $this->db->real_escape_string($s); }

    /** Provision satu WP. Mengembalikan nama DB tenant. */
    public function createTenant($wp_id, $nama, $jenis, $kecamatan, $adminEmail, $adminPass)
    {
        $wp_id = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $wp_id));
        if ($wp_id === '') throw new \Exception('wp_id tidak valid');
        if (!in_array($jenis, ['hotel', 'resto'], true)) throw new \Exception('jenis harus hotel/resto');

        // Cegah duplikat.
        $q = $this->db->query("SELECT 1 FROM `{$this->control}`.tenants WHERE wp_id='" . $this->esc($wp_id) . "'");
        if ($q && $q->num_rows) throw new \Exception("WP $wp_id sudah terdaftar");

        $this->ensureTemplate();
        $db = 'tenant_' . strtolower($wp_id);

        // Klon PENUH dari template bersih (termasuk artefak migrasi -> tenant baru lahir mutakhir).
        $this->cloneDb($this->template, $db, [], false);

        // Identitas tenant.
        $namaEsc = $this->esc($nama);
        $this->db->query("UPDATE `$db`.setting SET storename='$namaEsc'");
        $this->db->query("UPDATE `$db`.common_setting SET storename='$namaEsc'");

        // Tahun buku aktif = tahun kalender berjalan (tenant baru belum bertransaksi). Tanpanya form
        // booking/POS menolak simpan dan datepicker "Invalid date"; isi template bisa tahun lalu.
        $y = (int) date('Y');
        $this->db->query("DELETE FROM `$db`.tbl_financialyear");
        $this->db->query("INSERT INTO `$db`.tbl_financialyear (title,start_date,end_date,date_time,is_active,create_by)
            VALUES ('$y','$y-01-01','$y-12-31','" . date('Y-m-d H:i:s') . "','2','1')");

        // Admin milik WP (super-admin di tenant-nya).
        $emailEsc = $this->esc($adminEmail);
        $passHash = $this->esc(egzy_password_hash($adminPass));
        $upd = $this->db->query("UPDATE `$db`.user SET is_admin=1, usertype=1, status=1, password='$passHash'
                                 WHERE email='$emailEsc'");
        if ($this->db->affected_rows <= 0) {
            $fn = $this->esc('Admin ' . $nama);
            $this->db->query("INSERT INTO `$db`.user (firstname,email,password,status,usertype,is_admin)
                              VALUES ('$fn','$emailEsc','$passHash',1,1,1)");
        }

        // Registry + direktori (kontrol).
        $now = date('Y-m-d H:i:s');
        $this->db->query("INSERT INTO `{$this->control}`.tenants (wp_id,nama,db_name,jenis_usaha,kecamatan,status,dibuat)
            VALUES ('" . $this->esc($wp_id) . "','$namaEsc','" . $this->esc($db) . "','" . $this->esc($jenis) . "','" . $this->esc($kecamatan) . "',1,'$now')");
        $this->db->query("INSERT INTO `{$this->control}`.directory (email,wp_id,db_name,is_bapenda)
            VALUES ('$emailEsc','" . $this->esc($wp_id) . "','" . $this->esc($db) . "',0)
            ON DUPLICATE KEY UPDATE wp_id=VALUES(wp_id), db_name=VALUES(db_name), is_bapenda=0");

        // Master WP oversight (agar tampil di dashboard Bapenda).
        $this->db->query("INSERT INTO `{$this->oversight}`.pbjt_wp (wp_id,nama,jenis_usaha,kecamatan)
            VALUES ('" . $this->esc($wp_id) . "','$namaEsc','" . $this->esc($jenis) . "','" . $this->esc($kecamatan) . "')
            ON DUPLICATE KEY UPDATE nama=VALUES(nama), jenis_usaha=VALUES(jenis_usaha), kecamatan=VALUES(kecamatan)");

        return $db;
    }
}
