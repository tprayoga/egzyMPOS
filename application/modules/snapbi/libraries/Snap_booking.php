<?php
require_once __DIR__ . '/Snap_service.php';

/**
 * Pembayaran booking tamu via VA BNI, dengan kredensial BNI MILIK MASING-MASING HOTEL.
 *
 * Profil SNAP sebuah hotel = nama DB tenant-nya (mis. 'tenant_wp001'), atau dipetakan
 * eksplisit lewat config `booking_profiles` => [db_name => profil].
 * Pembayaran HANYA dicatat dari notifikasi bank yang sah (Booking_va_handler), tidak
 * pernah dari redirect browser.
 */
class Snap_booking
{
    public static function profileFor(array $cfg, $dbName)
    {
        $name = isset($cfg['booking_profiles'][$dbName]) ? $cfg['booking_profiles'][$dbName] : $dbName;
        return !empty($cfg['profiles'][$name]) ? $name : null;
    }

    /**
     * Terbitkan (atau pakai ulang) VA untuk booking. $booking = baris booked_info + nama tamu.
     */
    public static function issue(array $cfg, Snap_service $svc, $dbName, $booking)
    {
        $profile = self::profileFor($cfg, $dbName);
        if (!$profile) {
            throw new RuntimeException('Hotel ini belum mengaktifkan pembayaran Virtual Account BNI.');
        }
        $due = (float) $booking->total_price - (float) $booking->paid_amount;
        if ($due <= 0) {
            throw new RuntimeException('Booking ini sudah lunas.');
        }
        $hours = isset($cfg['booking_va_jam']) ? (int) $cfg['booking_va_jam'] : 24;
        $name = trim($booking->full_guest_name ?: 'Tamu') ;
        return $svc->issue($profile, 'booking', $dbName, $booking->booking_number, $name, $due, "+$hours hours");
    }
}

/**
 * Handler SNAP purpose 'booking': VA lunas -> catat pembayaran di DB tenant hotel,
 * dengan pembukuan yang sama seperti Hotel::insert_payment (gateway online lain).
 *
 * Berjalan di dalam request CodeIgniter (endpoint bank atau `php index.php snapbi/snap/redispatch`).
 */
class Booking_va_handler
{
    const METHOD_NAME = 'Virtual Account BNI';
    const COA_VA = 102010305;
    const COA_CUSTOMER = 102030101;
    const COA_HOTEL = 30301;

    public function onPaid(array $va)
    {
        if (!function_exists('get_instance')) {
            throw new RuntimeException('Handler booking butuh konteks CodeIgniter (pakai: php index.php snapbi/snap/redispatch)');
        }
        $CI =& get_instance();
        $tdb = $this->tenantDb($CI, $va['tenant_db']);

        // Helper akuntansi transaction() menulis ke $CI->db; arahkan sementara ke DB tenant.
        // Di HMVC, model membaca db dari CI::$APP, jadi keduanya dialihkan lalu dipulihkan.
        $oldDb = $CI->db;
        $oldApp = (class_exists('CI', false) && isset(CI::$APP->db)) ? CI::$APP->db : null;
        $CI->db = $tdb;
        if ($oldApp) CI::$APP->db = $tdb;
        try {
            $CI->load->helper('acc');
            $this->record($tdb, $va);
        } finally {
            $CI->db = $oldDb;
            if ($oldApp) CI::$APP->db = $oldApp;
            $tdb->close();
        }
    }

    private function tenantDb($CI, $dbName)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $dbName)) {
            throw new RuntimeException("Nama DB tenant tidak valid: $dbName");
        }
        $c = require APPPATH . 'config/pbjt_db.php';
        $ctrl = new mysqli($c['host'], $c['user'], $c['pass'], $c['control_db']);
        $st = $ctrl->prepare("SELECT 1 FROM tenants WHERE db_name=? AND status=1");
        $st->bind_param('s', $dbName);
        $st->execute();
        $known = (bool) $st->get_result()->fetch_row();
        $ctrl->close();
        if (!$known && $dbName !== $c['oversight_db']) {
            throw new RuntimeException("DB $dbName tidak terdaftar sebagai tenant");
        }
        $db = $CI->load->database([
            'hostname' => $c['host'], 'username' => $c['user'], 'password' => $c['pass'], 'database' => $dbName,
            'dbdriver' => 'mysqli', 'char_set' => 'utf8', 'dbcollat' => 'utf8_general_ci', 'db_debug' => FALSE,
        ], TRUE);
        if (!$db->conn_id) throw new RuntimeException("DB tenant $dbName tidak terjangkau");
        return $db;
    }

    private function record($db, array $va)
    {
        $marker = 'BNI VA ' . trim($va['va_no']) . ' / ' . $va['payment_request_id'];
        if ($db->where('details', $marker)->count_all_results('tbl_guestpayments') > 0) {
            return; // sudah dicatat (notifikasi berulang / redispatch)
        }
        $booking = $db->where('booking_number', $va['ref'])->get('booked_info')->row();
        if (!$booking) throw new RuntimeException("Booking {$va['ref']} tidak ada di {$va['tenant_db']}");

        $amount = (float) $va['paid_amount'];
        $today = date('Y-m-d');

        $db->trans_start();
        $db->set('paid_amount', 'paid_amount+' . $db->escape($amount), FALSE)->where('bookedid', $booking->bookedid)->update('booked_info');
        $db->where('bookedid', $booking->bookedid)->update('booked_details', ['payment_method' => self::METHOD_NAME, 'advance_amount' => $amount]);
        $db->set('balance', 'balance+' . $db->escape($amount), FALSE)->where('customerid', $booking->cutomerid)->update('customerinfo');

        $last = $db->select('invoice')->order_by('payid', 'desc')->limit(1)->get('tbl_guestpayments')->row();
        $invoice = str_pad((string) ((int) ($last ? $last->invoice : 0) + 1), 6, '0', STR_PAD_LEFT);
        $db->insert('tbl_guestpayments', [
            'bookedid' => $booking->bookedid, 'invoice' => $invoice, 'paydate' => $today,
            'paymenttype' => self::METHOD_NAME, 'paymentamount' => $amount, 'details' => $marker, 'book_type' => 0,
        ]);

        $db->trans_complete();
        if (!$db->trans_status()) {
            throw new RuntimeException('Gagal mencatat pembayaran booking ' . $va['ref'] . ': ' . $db->error()['message']);
        }

        // Pembukuan identik dengan Hotel::insert_payment, di luar transaksi seperti aslinya:
        // transaction() ikut memperbarui saldo awal per tahun buku, yang gagal diam-diam bila
        // hotel belum mengatur tahun buku. Itu tidak boleh membatalkan pembayaran yang sudah masuk.
        transaction($invoice, 'CIV', $today, self::COA_CUSTOMER, 'Customer debit for webiste advance payment Rent Invoice#' . $invoice, $amount, 0, 0, 1, 0, $today, 1);
        transaction($invoice, 'CIV', $today, self::COA_HOTEL, 'Hotel Credit for webiste advance payment Rent Invoice#' . $invoice, 0, $amount, 0, 1, 0, $today, 1);
        transaction($invoice, 'CIV', $today, self::COA_CUSTOMER, 'Customer Credit for webiste advance payment Rent Invoice' . $invoice, 0, $amount, 0, 1, 0, $today, 1);
        transaction($invoice, 'CIV', $today, self::COA_VA, 'Cash in BNI Virtual Account Debited For webiste advance payment Invoice#' . $invoice, $amount, 0, 0, 1, 0, $today, 1);
    }
}
