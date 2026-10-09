<?php
/**
 * UAT EGZY — skenario penerimaan per kebutuhan SRS (FR-01..FR-12), dijalankan end-to-end lewat HTTP
 * sebagai pengguna sungguhan pada SALINAN data (DB uat_*), tidak menyentuh data demo/produksi.
 *
 *   UAT_PASSWORD='<password akun demo>' php tests/uat/uat_egzy.php [hasil.json] [--keep]
 *
 * Lingkungan: backup terbaru (pbjt_backup) dipulihkan ke uat_ov (oversight), uat_control (kontrol),
 * uat_hotel (WP001 Melati), uat_resto (WP009 Bahari); migrasi 004-012 diterapkan; aplikasi kedua di
 * port 8803 dengan PBJT_CONTROL_DB=uat_control PBJT_OVERSIGHT_DB=uat_ov. Semua DB uat_* dihapus di akhir
 * (kecuali --keep). Password tidak pernah ditulis di repo: diambil dari env UAT_PASSWORD.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('PBJT_CLI', true);
date_default_timezone_set('Asia/Jakarta');
$root = dirname(__DIR__, 2);
$c = require $root . '/application/config/pbjt_db.php';
$PW = getenv('UAT_PASSWORD');
if (!$PW) { fwrite(STDERR, "Set env UAT_PASSWORD (password akun demo).\n"); exit(2); }
$OUT = null; $KEEP = false;
foreach (array_slice($argv, 1) as $a) { if ($a === '--keep') $KEEP = true; else $OUT = $a; }
$PORT = 8803;
$BASE = "http://127.0.0.1:$PORT";
$m = new mysqli($c['host'], $c['user'], $c['pass']);
$m->query("SET SESSION sql_mode=''");
$q = function ($db, $sql) use ($m) { $m->select_db($db); $r = $m->query($sql); if ($r === false) throw new RuntimeException("$db: {$m->error} | $sql"); return $r === true ? $m->affected_rows : $r->fetch_all(MYSQLI_ASSOC); };
$one = function ($db, $sql) use ($q) { $r = $q($db, $sql); return $r ? reset($r[0]) : null; };

// ------------------------------------------------------------------ hasil
$RES = []; $CUR = null;
function uat($id, $area, $skenario, $langkah, $harapan, callable $fn)
{
    global $RES;
    $t0 = microtime(true);
    try { list($ok, $aktual, $bukti) = $fn(); }
    catch (Throwable $e) { $ok = false; $aktual = 'Error: ' . $e->getMessage(); $bukti = ''; }
    $RES[] = compact('id', 'area', 'skenario', 'langkah', 'harapan', 'aktual', 'bukti') + ['status' => $ok ? 'Lulus' : 'Gagal', 'detik' => round(microtime(true) - $t0, 1)];
    printf("  [%s] %s %s — %s\n", $ok ? 'LULUS' : 'GAGAL', $id, $skenario, $aktual);
}

// ------------------------------------------------------------------ lingkungan
echo "Menyiapkan lingkungan UAT\n";
$dir = $c['backup_dir'];
$latest = function ($db) use ($dir) { $f = glob("$dir/{$db}_*.sql"); rsort($f); return $f[0] ?? null; };
$ENV = ['uat_ov' => 'hotelv3', 'uat_control' => $c['control_db'], 'uat_hotel' => 'tenant_wp001', 'uat_resto' => 'tenant_wp009'];
$tmp = sys_get_temp_dir() . '/uat_egzy_' . getmypid(); @mkdir($tmp, 0700, true);
$cleanup = function () use ($m, $ENV, $KEEP) {
    if ($KEEP) return;
    foreach (array_keys($ENV) as $db) $m->query("DROP DATABASE IF EXISTS `$db`");
    foreach (['tenant_uatx', 'uat_restore_check'] as $db) $m->query("DROP DATABASE IF EXISTS `$db`");
};
$cleanup();
foreach ($ENV as $db => $src) {
    $f = $latest($src);
    if (!$f) { fwrite(STDERR, "Backup $src tidak ada di $dir\n"); exit(1); }
    $m->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    exec('php ' . escapeshellarg("$root/pbjt_backup.php") . ' restore ' . escapeshellarg($db) . ' ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
    if ($rc !== 0) { fwrite(STDERR, "Restore $db gagal\n"); exit(1); }
    echo "  $db <- " . basename($f) . "\n";
}
$mig = function ($db, $v) use ($m, $root) {
    $m->select_db($db);
    if (!$m->multi_query(file_get_contents(glob("$root/application/migrations_pbjt/{$v}_*.sql")[0]))) return $m->error;
    while (true) { if ($r = $m->store_result()) $r->free(); if (!$m->more_results()) break; if (!$m->next_result()) return $m->error; }
    return '';
};
$VERS = ['004', '005', '006', '007', '008', '009', '010', '011', '012'];
foreach (['uat_ov', 'uat_hotel', 'uat_resto'] as $db) foreach ($VERS as $v) { $e = $mig($db, $v); if ($e) { fwrite(STDERR, "Migrasi $v di $db: $e\n"); exit(1); } }
// registry tenant salinan: hanya WP001 & WP009 aplikasi; WP tapping box tetap dari data oversight
$q('uat_control', "UPDATE tenants SET status = 0");
$q('uat_control', "UPDATE tenants SET db_name='uat_hotel', status=1 WHERE wp_id='WP001'");
$q('uat_control', "UPDATE tenants SET db_name='uat_resto', status=1 WHERE wp_id='WP009'");
$q('uat_control', "UPDATE directory SET db_name='uat_hotel' WHERE email='melati@egzy.test'");
$q('uat_control', "UPDATE directory SET db_name='uat_resto' WHERE email='bahari@egzy.test'");
$app = proc_open("exec env PBJT_CONTROL_DB=uat_control PBJT_OVERSIGHT_DB=uat_ov php -S 127.0.0.1:$PORT " . escapeshellarg("$root/server_router.php"),
    [0 => ['pipe', 'r'], 1 => ['file', "$tmp/app.log", 'a'], 2 => ['file', "$tmp/app.log", 'a']], $pp, $root);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $PORT); $i++) usleep(100000);

// ------------------------------------------------------------------ klien HTTP per pengguna
class U
{
    public $jar; private $base; private $root;
    function __construct($base, $root, $tmp, $name) { $this->base = $base; $this->root = $root; $this->jar = "$tmp/$name.jar"; @unlink($this->jar); touch($this->jar); }
    function cookie($n) { return preg_match('/\t' . preg_quote($n, '/') . '\t(\S+)/', (string) file_get_contents($this->jar), $mm) ? $mm[1] : ''; }
    function req($method, $path, $post = null, $ajax = false, $rawCookie = null)
    {
        $ch = curl_init($this->base . $path);
        $o = [CURLOPT_RETURNTRANSFER => 1, CURLOPT_HEADER => 1, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60,
              CURLOPT_HTTPHEADER => $ajax ? ['X-Requested-With: XMLHttpRequest'] : []];
        if ($rawCookie !== null) $o[CURLOPT_COOKIE] = $rawCookie; else { $o[CURLOPT_COOKIEJAR] = $this->jar; $o[CURLOPT_COOKIEFILE] = $this->jar; }
        if ($post !== null) { $post['csrf_test_name'] = $this->cookie('csrf_cookie_name'); $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
        curl_setopt_array($ch, $o);
        $r = (string) curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $h = substr($r, 0, $hs);
        return ['code' => $code, 'loc' => preg_match('/^Location: (\S+)/mi', $h, $l) ? $l[1] : '', 'body' => substr($r, $hs)];
    }
    function login($email, $pw)
    {
        $this->req('GET', '/login');
        $cap = preg_match('/captcha\|s:\d+:"([^"]*)"/', (string) @file_get_contents("{$this->root}/application/cache/temp/ci_session" . $this->cookie('ci_session')), $mm) ? $mm[1] : '';
        $r = $this->req('POST', '/login', ['email' => $email, 'password' => $pw, 'captcha' => $cap]);
        return $r;
    }
}
$hotel = new U($BASE, $root, $tmp, 'hotel');
$resto = new U($BASE, $root, $tmp, 'resto');
$bap = new U($BASE, $root, $tmp, 'bapenda');
$today = date('Y-m-d');
$masaKini = date('Y-m');

try {
    echo "\nA. Akses dan isolasi\n";
    uat('UAT-01', 'Akses', 'Login WP hotel diarahkan ke database WP-nya', 'Login melati@egzy.test; buka Customer list',
        'Masuk dashboard; daftar customer berisi pelanggan Melati', function () use ($hotel, $PW, $one) {
            $r = $hotel->login('melati@egzy.test', $PW);
            $phone = $one('uat_hotel', "SELECT cust_phone FROM customerinfo WHERE cust_phone <> '' ORDER BY customerid DESC LIMIT 1");
            $l = $hotel->req('GET', '/customer/customer-list');
            $ok = $r['code'] === 302 && strpos($r['loc'], 'dashboard') !== false && strpos($l['body'], $phone) !== false && $hotel->cookie('tnt') === 'uat_hotel';
            return [$ok, $ok ? 'Masuk ke DB uat_hotel; pelanggan Melati tampil' : "login {$r['code']} {$r['loc']}, tnt=" . $hotel->cookie('tnt'), "tnt=" . $hotel->cookie('tnt')];
        });
    uat('UAT-02', 'Akses', 'Cookie tenant diganti ke WP lain ditolak', 'Dengan sesi Melati, ubah cookie tnt ke DB Bahari, buka Customer list',
        'Dialihkan ke login; data Bahari tidak tampil; sesi diakhiri', function () use ($resto, $hotel, $one) {
            $phone = $one('uat_resto', "SELECT cust_phone FROM customerinfo WHERE cust_phone <> '' ORDER BY customerid DESC LIMIT 1");
            $probe = new U($GLOBALS['BASE'], $GLOBALS['root'], $GLOBALS['tmp'], 'probe');
            $r = $probe->req('GET', '/customer/customer-list', null, false, 'ci_session=' . $hotel->cookie('ci_session') . '; tnt=uat_resto');
            $leak = $phone && strpos($r['body'], (string) $phone) !== false;
            $ok = $r['code'] === 302 && strpos($r['loc'], 'login') !== false && !$leak;
            return [$ok, $ok ? 'Ditolak (302 ke /login), tidak ada data Bahari' : "HTTP {$r['code']} {$r['loc']} bocor=" . ($leak ? 'ya' : 'tidak'), 'sesi Melati + tnt=uat_resto'];
        });
    $hotel = new U($BASE, $root, $tmp, 'hotel2'); $hotel->login('melati@egzy.test', $PW); // sesi lama sudah diakhiri oleh UAT-02
    uat('UAT-03', 'Akses', 'Bapenda tidak bisa membuka modul operasional', 'Login bapenda@egzy.test; buka Booking list dan POS',
        'Dialihkan ke Pengawasan PBJT', function () use ($bap, $PW) {
            $bap->login('bapenda@egzy.test', $PW);
            $a = $bap->req('GET', '/room_reservation/booking-list'); $b = $bap->req('GET', '/ordermanage/order/pos_invoice');
            $ok = $a['code'] === 302 && strpos($a['loc'], '/pbjt') !== false && $b['code'] === 302 && strpos($b['loc'], '/pbjt') !== false;
            return [$ok, $ok ? 'Keduanya dialihkan ke /pbjt' : "booking {$a['code']} {$a['loc']}, POS {$b['code']} {$b['loc']}", ''];
        });
    uat('UAT-04', 'Akses', 'WP tidak bisa memakai fungsi Bapenda', 'Sebagai Melati buka /pbjt/daftar dan kirim POST /pbjt/terbit_va',
        'Ditolak dengan pesan "Khusus Bapenda"; tidak ada tagihan baru', function () use ($hotel, $one) {
            $before = (int) $one('uat_ov', "SELECT COUNT(*) FROM pbjt_tagihan");
            $a = $hotel->req('GET', '/pbjt/daftar'); $b = $hotel->req('POST', '/pbjt/terbit_va', ['masa' => '2026-09', 'wp_id' => 'WP001']);
            $after = (int) $one('uat_ov', "SELECT COUNT(*) FROM pbjt_tagihan");
            $ok = strpos($a['body'], 'Khusus Bapenda') !== false && strpos($b['body'], 'Khusus Bapenda') !== false && $before === $after;
            return [$ok, $ok ? 'Keduanya "Khusus Bapenda"; jumlah tagihan tetap' : 'pesan tidak sesuai / tagihan berubah', "tagihan $before -> $after"];
        });
    uat('UAT-05', 'Akses', 'Login dengan password salah ditolak', 'Login melati@egzy.test dengan password salah',
        'Tetap di halaman login', function () {
            $u = new U($GLOBALS['BASE'], $GLOBALS['root'], $GLOBALS['tmp'], 'salah');
            $r = $u->login('melati@egzy.test', 'salah-password-uat');
            $ok = !($r['code'] === 302 && strpos($r['loc'], 'dashboard') !== false);
            return [$ok, $ok ? 'Ditolak' : 'Berhasil masuk!', "HTTP {$r['code']}"];
        });

    echo "\nB. Hotel\n";
    uat('UAT-06', 'Hotel', 'Tahun buku dibuat otomatis bila tidak ada', 'Kosongkan tbl_financialyear; buka Booking list',
        'Tahun buku aktif tahun berjalan terbentuk; batas tanggal form terisi', function () use ($hotel, $q, $one) {
            $q('uat_hotel', "DELETE FROM tbl_financialyear");
            $r = $hotel->req('GET', '/room_reservation/booking-list');
            $n = (int) $one('uat_hotel', "SELECT COUNT(*) FROM tbl_financialyear WHERE start_date <= CURDATE() AND end_date >= CURDATE() AND is_active='2'");
            $ok = $n === 1 && preg_match('/id="findate" value="\d{4}-12-31"/', $r['body']);
            return [$ok, $ok ? 'Tahun buku ' . date('Y') . ' aktif terbentuk' : "baris=$n", ''];
        });
    $room = $q('uat_hotel', "SELECT r.roomid, r.rate, f.roomno FROM roomdetails r JOIN tbl_roomnofloorassign f ON f.roomid=r.roomid ORDER BY r.roomid, f.roomno LIMIT 1")[0];
    $ci = date('Y-m-d', strtotime('+60 days')); $co = date('Y-m-d', strtotime('+62 days'));
    $bookingPost = function ($name) use ($room, $ci, $co) {
        return ['datefilter1' => "$ci 14:00", 'datefilter2' => "$co 12:00", 'room_type' => $room['roomid'], 'roomno' => $room['roomno'], 'adults' => 2,
            'children' => 0, 'rent' => $room['rate'], 'discount_price' => 0, 'complementary' => 'no', 'userid' => '', 'name' => $name,
            'mobile' => '0899' . substr((string) crc32($name), 0, 7), 'email' => strtolower($name) . '@uat.test', 'lastname' => 'Uat', 'gender' => 'Female',
            'pid' => '', 'bed' => 0, 'person' => 0, 'child' => 0, 'amount3' => 0, 'extrastart' => '', 'extraend' => '', 'advanceamount' => ''];
    };
    uat('UAT-07', 'Hotel', 'Booking baru tampil di Booking list', "Room Booking: kamar {$room['roomno']}, $ci s.d. $co (2 malam), simpan; buka Booking list (Pending)",
        'Tersimpan "Success"; booking tampil di daftar Pending', function () use ($hotel, $bookingPost, $one) {
            $r = $hotel->req('POST', '/room_reservation/room_reservation/newBooking', $bookingPost('SitiUat'), true);
            $bn = $one('uat_hotel', "SELECT booking_number FROM booked_info WHERE full_guest_name='SitiUat'");
            $l = $hotel->req('POST', '/room_reservation/room_reservation/bookingdatatable', ['draw' => 1, 'start' => 0, 'length' => 200,
                'order' => [['column' => 1, 'dir' => 'desc']], 'search' => ['value' => ''], 'status' => '0'], true);
            $ok = strpos($r['body'], 'Success') !== false && $bn && strpos($l['body'], (string) $bn) !== false;
            return [$ok, $ok ? "Booking $bn tersimpan dan tampil" : 'respons: ' . substr(strip_tags($r['body']), 0, 80), "booking_number=$bn"];
        });
    uat('UAT-08', 'Hotel', 'PBJT booking dihitung engine (harga termasuk pajak)', 'Periksa kolom pajak booking UAT-07',
        'bruto = tarif x 2 malam; pbjt = bruto - bruto/1,1 (dibulatkan); dpp + pbjt = bruto', function () use ($one, $q, $room) {
            $b = $q('uat_hotel', "SELECT total_price, jumlah_malam, bruto, dpp, pbjt, tarif_snapshot FROM booked_info WHERE full_guest_name='SitiUat'")[0];
            $bruto = round($b['total_price'] * 2, 2); $pbjt = round($bruto - $bruto / 1.1);
            $ok = (int) $b['jumlah_malam'] === 2 && (float) $b['bruto'] == $bruto && (float) $b['pbjt'] == $pbjt && round($b['dpp'] + $b['pbjt'], 2) == $bruto;
            return [$ok, "bruto {$b['bruto']}, dpp {$b['dpp']}, pbjt {$b['pbjt']} (harapan pbjt $pbjt)", json_encode($b)];
        });
    uat('UAT-09', 'Hotel', 'Booking ganda kamar & tanggal sama ditolak', 'Ulangi booking kamar dan tanggal yang sama',
        'Ditolak "not available"; tidak ada booking kedua', function () use ($hotel, $bookingPost, $one) {
            $r = $hotel->req('POST', '/room_reservation/room_reservation/newBooking', $bookingPost('BudiUat'), true);
            $n = (int) $one('uat_hotel', "SELECT COUNT(*) FROM booked_info WHERE full_guest_name='BudiUat'");
            $ok = stripos($r['body'], 'not available') !== false && $n === 0;
            return [$ok, $ok ? 'Ditolak: ' . trim(strip_tags($r['body'])) : 'respons: ' . substr(strip_tags($r['body']), 0, 80), ''];
        });
    uat('UAT-10', 'Hotel', 'Filter status Booking list', 'Pilih status Checkout, lalu Semua',
        'Checkout menampilkan booking selesai; Semua = seluruh booking', function () use ($hotel, $one) {
            $get = function ($st) use ($hotel) { $r = $hotel->req('POST', '/room_reservation/room_reservation/bookingdatatable', ['draw' => 1, 'start' => 0, 'length' => 1,
                'order' => [['column' => 1, 'dir' => 'desc']], 'search' => ['value' => ''], 'status' => $st], true); return json_decode($r['body'], true)['recordsTotal'] ?? -1; };
            $co = $get('5'); $all = $get('all');
            $dbCo = (int) $one('uat_hotel', "SELECT COUNT(*) FROM booked_info WHERE bookingstatus=5"); $dbAll = (int) $one('uat_hotel', "SELECT COUNT(*) FROM booked_info");
            $ok = $co === $dbCo && $co > 0 && $all === $dbAll;
            return [$ok, "Checkout $co (DB $dbCo), Semua $all (DB $dbAll)", ''];
        });
    uat('UAT-11', 'Hotel', 'Pencarian Booking list aman dari injeksi SQL', "Cari dengan teks: x' OR 1=1-- ",
        'Tidak ada baris; tidak error', function () use ($hotel) {
            $r = $hotel->req('POST', '/room_reservation/room_reservation/bookingdatatable', ['draw' => 1, 'start' => 0, 'length' => 5,
                'order' => [['column' => 1, 'dir' => 'desc']], 'search' => ['value' => "x' OR 1=1-- "], 'status' => 'all'], true);
            $n = json_decode($r['body'], true)['recordsTotal'] ?? -1;
            return [$r['code'] === 200 && $n === 0, "HTTP {$r['code']}, $n baris", ''];
        });
    uat('UAT-12', 'Hotel', 'Waktu transaksi tercatat WIB', 'Bandingkan date_time booking UAT-07 dengan jam WIB saat uji',
        'Selisih kurang dari 10 menit', function () use ($one) {
            $dt = $one('uat_hotel', "SELECT date_time FROM booked_info WHERE full_guest_name='SitiUat'");
            $diff = abs(strtotime($dt) - time());
            return [$diff < 600, "date_time $dt, selisih {$diff} detik dari WIB", ''];
        });

    echo "\nC. Restoran\n";
    uat('UAT-13', 'Restoran', 'POS menampilkan PBJT dari tarif Pemda', 'Login bahari@egzy.test; buka POS Invoice',
        'Label "PBJT (10%)"; tarif 0.1 dari konfigurasi', function () use ($resto, $PW) {
            $resto->login('bahari@egzy.test', $PW);
            $r = $resto->req('GET', '/ordermanage/order/pos_invoice');
            $ok = strpos($r['body'], 'PBJT (10%') !== false && strpos($r['body'], 'id="pbjt_tarif" value="0.1"') !== false;
            return [$ok, $ok ? 'Label PBJT (10%) dan tarif 0.1 tampil' : 'tidak ditemukan', ''];
        });
    uat('UAT-14', 'Restoran', 'Order POS: PBJT ditetapkan server, bukan browser', 'Tambah 2 menu ke keranjang; Place Order dengan VAT dari browser = 0',
        'Bill VAT = 10% x DPP; total = DPP + PBJT; tersimpan dpp/pbjt/tarif', function () use ($resto, $q, $one, $today) {
            $it = $q('uat_resto', "SELECT f.ProductsID pid, f.CategoryID cid, f.ProductName nm, v.variantid vid, v.price FROM item_foods f JOIN variant v ON v.menuid=f.ProductsID ORDER BY f.ProductsID LIMIT 1")[0];
            $resto->req('POST', '/ordermanage/order/posaddtocart', ['pid' => $it['pid'], 'itemname' => $it['nm'], 'varientname' => 'Reguler', 'qty' => 2,
                'price' => $it['price'], 'catid' => $it['cid'], 'sizeid' => $it['vid'], 'isgroup' => 0], true);
            $sub = 2 * $it['price'];
            $before = (int) $one('uat_resto', "SELECT COALESCE(MAX(order_id),0) FROM customer_order");
            $resto->req('POST', '/ordermanage/order/pos_order', ['customer_name' => 1, 'ctypeid' => 1, 'waiter' => '', 'tableid' => 1, 'card_type' => 4,
                'isonline' => 0, 'order_date' => $today, 'grandtotal' => $sub, 'customernote' => '', 'invoice_discount' => 0, 'service_charge' => 0,
                'vat' => 0, 'subtotal' => $sub, 'cookedtime' => '00:15:00', 'multiplletaxvalue' => '', 'table_member_multi' => 0, 'tablemember' => 2,
                'delivercom' => 0, 'thirdpartyinvoice' => 0, 'assigncard_terminal' => '', 'assignbank' => '', 'assignlastdigit' => ''], true);
            $o = $q('uat_resto', "SELECT o.order_id, o.totalamount, o.dpp, o.pbjt, o.tarif_snapshot, b.total_amount, b.discount, b.service_charge, b.VAT, b.bill_amount
                FROM customer_order o JOIN bill b ON b.order_id=o.order_id WHERE o.order_id > $before ORDER BY o.order_id DESC LIMIT 1");
            if (!$o) return [false, 'order tidak tersimpan', "subtotal $sub"];
            $o = $o[0]; $dpp = $o['total_amount'] - $o['discount'] + $o['service_charge']; $pb = round($dpp * 0.1);
            $ok = (float) $o['VAT'] == $pb && (float) $o['bill_amount'] == round($dpp + $pb, 2) && (float) $o['pbjt'] == $pb && (float) $o['tarif_snapshot'] == 0.1;
            return [$ok, "Order #{$o['order_id']}: DPP $dpp, VAT {$o['VAT']} (harapan $pb), total {$o['bill_amount']}", json_encode($o)];
        });
    uat('UAT-15', 'Restoran', 'Dashboard restoran & ringkasan restoran di hotel', 'Buka Dashboard sebagai Bahari dan sebagai Melati',
        'Bahari: dashboard restoran; Melati (hotel + restoran): kartu restoran di dashboard hotel', function () use ($resto, $hotel) {
            $a = $resto->req('GET', '/dashboard/home'); $b = $hotel->req('GET', '/dashboard/home');
            $ok = strpos($a['body'], 'Omzet order selesai per bulan') !== false && strpos($b['body'], 'PBJT restoran bulan ini') !== false && strpos($b['body'], 'today_booking') === false;
            return [$ok, $ok ? 'Keduanya sesuai' : 'tidak sesuai', ''];
        });
    uat('UAT-16', 'Restoran', 'Tax Management hanya untuk komponen non-PBJT', 'Tambah "PB1 Restoran 10%", lalu "Service Charge 5%"',
        'PB1 ditolak & dicatat; Service Charge tersimpan', function () use ($resto, $one) {
            $resto->req('POST', '/tax_management/tax/create', ['tax' => 'PB1 Restoran UAT', 'rate' => 10, 'reg_no' => '', 'isactive' => 1]);
            $resto->req('POST', '/tax_management/tax/create', ['tax' => 'Service Charge UAT', 'rate' => 5, 'reg_no' => '', 'isactive' => 0]);
            $pb1 = (int) $one('uat_resto', "SELECT COUNT(*) FROM tbl_taxmgt WHERE taxname='PB1 Restoran UAT'");
            $sc = (int) $one('uat_resto', "SELECT COUNT(*) FROM tbl_taxmgt WHERE taxname='Service Charge UAT'");
            $log = (int) $one('uat_resto', "SELECT COUNT(*) FROM accesslog WHERE action_done='Ditolak' AND remarks LIKE '%PB1 Restoran UAT%'");
            return [$pb1 === 0 && $sc === 1 && $log === 1, "PB1 tersimpan=$pb1, Service Charge tersimpan=$sc, log penolakan=$log", ''];
        });

    echo "\nD. Stok\n";
    uat('UAT-17', 'Stok', 'Produk rusak mengurangi stok di laporan', 'Pembelian 50 kg disiapkan; catat produk rusak 2 kg; buka Stock report',
        'Laporan: dibeli 50, rusak 2, stok 48', function () use ($resto, $q) {
            $q('uat_resto', "INSERT INTO products (id,product_name,category_id,uom_id,stock,is_active) VALUES (880001,'Beras UAT',4,1,50,1)");
            $q('uat_resto', "INSERT INTO tbl_reuseableproduct (product_id, ready) VALUES (880001, 50)");
            $q('uat_resto', "INSERT INTO purchaseitem (purID,invoiceid,suplierID,total_price,details,purchasedate,savedby,status) VALUES (880001,'UAT-INV',1,600000,'',CURDATE(),1,0)");
            $q('uat_resto', "INSERT INTO purchase_details (purchaseid,proid,quantity,unitname,price,totalprice,purchaseby,purchasedate) VALUES (880001,880001,50,'kg',12000,600000,1,CURDATE())");
            $resto->req('POST', '/units/products/descreate', ['product_id' => 880001, 'quantity' => 2, 'queue' => 'ready', 'comments' => 'basah', 'charge' => 0]);
            $b = $resto->req('GET', '/reports/stock-report')['body'];
            $i = strpos($b, 'Beras UAT'); $row = $i === false ? '' : substr($b, $i, strpos($b, '</tr>', $i) - $i);
            $cells = array_map(function ($x) { return trim(strip_tags($x)); }, explode('</td>', $row));
            $ok = isset($cells[5]) && strpos($cells[1], '50') === 0 && strpos($cells[3], '2') === 0 && strpos($cells[5], '48') === 0;
            return [$ok, $ok ? 'Dibeli 50, rusak 2, stok 48' : 'baris: ' . implode(' | ', $cells), ''];
        });

    echo "\nE. Pengawasan PBJT\n";
    uat('UAT-18', 'PBJT', 'Dashboard Bapenda menampilkan semua WP', 'Sebagai Bapenda buka /pbjt masa 2026-09',
        'Tabel tagihan memuat 10 WP', function () use ($bap, $one) {
            $b = $bap->req('GET', '/pbjt?masa=2026-09')['body'];
            $n = 0; foreach ($GLOBALS['q']('uat_ov', "SELECT wp_id FROM pbjt_wp") as $w) if (strpos($b, $w['wp_id']) !== false) $n++;
            $total = (int) $one('uat_ov', "SELECT COUNT(*) FROM pbjt_wp");
            return [$n === $total && $total >= 10, "$n dari $total WP tampil", ''];
        });
    uat('UAT-19', 'PBJT', 'Rekonsiliasi menandai WP kurang lapor', 'Bapenda jalankan rekonsiliasi masa 2026-09',
        'Guest House Kenanga (WP003, outlet QRIS kedua tanpa tapping box) muncul sebagai temuan: QRIS melebihi omzet terlapor', function () use ($bap) {
            $bap->req('GET', '/pbjt/reconcile?masa=2026-09');
            $t = $GLOBALS['q']('uat_ov', "SELECT wp_id, skor_risiko, omzet_terlapor, qris_teragregasi, potensi_rp FROM pbjt_temuan WHERE masa='2026-09' AND wp_id='WP003'");
            $ok = $t && $t[0]['omzet_terlapor'] > 0 && $t[0]['qris_teragregasi'] > $t[0]['omzet_terlapor'];
            return [$ok, $ok ? 'Temuan WP003: QRIS Rp ' . number_format($t[0]['qris_teragregasi'], 0, ',', '.') . ' > omzet Rp ' . number_format($t[0]['omzet_terlapor'], 0, ',', '.') . ", skor {$t[0]['skor_risiko']}" : 'tidak ada temuan WP003: ' . json_encode($t), ''];
        });
    uat('UAT-20', 'PBJT', 'Omzet pengawasan sama dengan transaksi WP', "Bapenda jalankan rekonsiliasi masa $masaKini; bandingkan omzet WP009 hari ini dengan order di DB Bahari",
        'omzet_bruto = jumlah (dpp + pbjt) order hari ini yang tidak batal; pajak = jumlah pbjt', function () use ($bap, $one, $today, $masaKini) {
            $bap->req('GET', "/pbjt/reconcile?masa=$masaKini");
            $ov = $GLOBALS['q']('uat_ov', "SELECT omzet_bruto, pajak_terlapor FROM pbjt_omzet_harian WHERE wp_id='WP009' AND tanggal='$today' AND device_id='APP'");
            $tn = $GLOBALS['q']('uat_resto', "SELECT ROUND(SUM(dpp+pbjt)) v, ROUND(SUM(pbjt)) p FROM customer_order WHERE order_date='$today' AND order_status<>5")[0];
            $ok = $ov && (int) $ov[0]['omzet_bruto'] === (int) $tn['v'] && (int) $ov[0]['pajak_terlapor'] === (int) $tn['p'];
            return [$ok, $ov ? "Pengawasan {$ov[0]['omzet_bruto']} / pajak {$ov[0]['pajak_terlapor']}; tenant {$tn['v']} / {$tn['p']}" : 'baris omzet tidak ada', ''];
        });
    $late = $GLOBALS['q']('uat_ov', "SELECT id, wp_id, masa FROM pbjt_tagihan WHERE (status='terbit' AND jatuh_tempo < CURDATE()) OR (status='lunas' AND DATE(lunas_pada) > jatuh_tempo) ORDER BY masa LIMIT 1");
    $STPD = null;
    uat('UAT-21', 'PBJT', 'Terbitkan STPD untuk tagihan terlambat', 'Bapenda: Terbitkan STPD pada tagihan terlambat (' . ($late ? "{$late[0]['wp_id']} masa {$late[0]['masa']}" : '-') . '); buka dokumen',
        'Dokumen STPD memuat nomor, pokok, bunga, total, watermark DRAFT', function () use ($bap, $late, &$STPD) {
            if (!$late) return [false, 'tidak ada tagihan terlambat di data', ''];
            $r = $bap->req('POST', '/pbjt/terbit_stpd', ['masa' => $late[0]['masa'], 'tagihan_id' => $late[0]['id']]);
            $STPD = $GLOBALS['one']('uat_ov', "SELECT MAX(id) FROM pbjt_stpd WHERE tagihan_id={$late[0]['id']}");
            $d = $bap->req('GET', "/pbjt/stpd/$STPD")['body'];
            $s = $GLOBALS['q']('uat_ov', "SELECT nomor, jumlah_bulan, bunga, total FROM pbjt_stpd WHERE id=" . (int) $STPD)[0] ?? [];
            $ok = $STPD && strpos($d, 'class="wm">DRAFT') !== false && strpos($d, $s['nomor']) !== false && strpos($d, number_format($s['bunga'], 0, ',', '.')) !== false;
            return [$ok, $ok ? "{$s['nomor']}: {$s['jumlah_bulan']} bulan, bunga Rp " . number_format($s['bunga'], 0, ',', '.') . ', total Rp ' . number_format($s['total'], 0, ',', '.') . ', DRAFT' : 'dokumen tidak sesuai', "stpd_id=$STPD"];
        });
    uat('UAT-22', 'PBJT', 'WP tidak bisa membuka STPD milik WP lain', 'Sebagai Melati buka dokumen STPD dari UAT-21',
        '"Akses ditolak"', function () use ($hotel, &$STPD) {
            $b = $hotel->req('GET', "/pbjt/stpd/$STPD")['body'];
            $ok = $STPD && strpos($b, 'Akses ditolak') !== false && strpos($b, 'SURAT TAGIHAN') === false;
            return [$ok, $ok ? 'Ditolak' : 'dokumen terbuka!', ''];
        });
    uat('UAT-23', 'PBJT', 'Bapenda mendaftarkan WP baru (provisioning)', 'Kelola Tenant: daftarkan UATX "Resto Uji UAT"; login sebagai admin barunya',
        'Database tenant baru terbentuk; admin baru bisa login ke database itu', function () use ($bap, $one) {
            $email = 'admin.uatx@uat.test'; $pass = 'UatX-' . bin2hex(random_bytes(4));
            $bap->req('POST', '/pbjt/simpan', ['wp_id' => 'UATX', 'nama' => 'Resto Uji UAT', 'jenis' => 'resto', 'kecamatan' => 'Uji', 'email' => $email, 'password' => $pass]);
            $page = $bap->req('GET', '/pbjt/daftar')['body'];
            $flash = preg_match('/(Gagal:[^<"]{0,200})/', $page, $fm) ? $fm[1] : '';
            $db = $one('uat_control', "SELECT db_name FROM tenants WHERE wp_id='UATX'");
            if (!$db) return [false, 'tenant tidak terbentuk. ' . ($flash ?: 'tanpa pesan'), ''];
            $u = new U($GLOBALS['BASE'], $GLOBALS['root'], $GLOBALS['tmp'], 'uatx'); $r = $u->login($email, $pass);
            $ok = $db === 'tenant_uatx' && $r['code'] === 302 && strpos($r['loc'], 'dashboard') !== false && $u->cookie('tnt') === 'tenant_uatx';
            return [$ok, $ok ? 'tenant_uatx terbentuk; admin baru masuk ke DB-nya' : "db=$db login {$r['code']} {$r['loc']}", ''];
        });

    echo "\nF. Operasional\n";
    uat('UAT-24', 'Operasional', 'Backup dan restore satu database', 'pbjt_backup.php backup uat_hotel; restore ke DB baru; bandingkan',
        'Jumlah tabel dan jumlah booking sama', function () use ($root, $m, $one, $c) {
            exec('php ' . escapeshellarg("$root/pbjt_backup.php") . ' backup uat_hotel 2>&1', $o1, $rc1);
            $f = glob("{$c['backup_dir']}/uat_hotel_*.sql"); rsort($f);
            $m->query("CREATE DATABASE uat_restore_check");
            exec('php ' . escapeshellarg("$root/pbjt_backup.php") . ' restore uat_restore_check ' . escapeshellarg($f[0]) . ' 2>&1', $o2, $rc2);
            $t1 = (int) $one('uat_hotel', "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='uat_hotel'");
            $t2 = (int) $one('uat_hotel', "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='uat_restore_check'");
            $b1 = (int) $one('uat_hotel', "SELECT COUNT(*) FROM uat_hotel.booked_info"); $b2 = (int) $one('uat_hotel', "SELECT COUNT(*) FROM uat_restore_check.booked_info");
            @unlink($f[0]);
            return [$rc1 === 0 && $rc2 === 0 && $t1 === $t2 && $b1 === $b2, "tabel $t1/$t2, booking $b1/$b2", basename($f[0])];
        });
    uat('UAT-25', 'Operasional', 'Migrasi aman dijalankan ulang', 'Terapkan ulang migrasi 005-012 pada DB hotel',
        'Tanpa error; data tetap', function () use ($mig, $one) {
            $before = (int) $one('uat_hotel', "SELECT COUNT(*) FROM booked_info"); $err = '';
            foreach (['005', '006', '007', '008', '009', '010', '011', '012'] as $v) { $e = $mig('uat_hotel', $v); if ($e) $err .= "$v: $e "; }
            $after = (int) $one('uat_hotel', "SELECT COUNT(*) FROM booked_info");
            return [$err === '' && $before === $after, $err ?: "tanpa error; booking $before = $after", ''];
        });
} finally {
    proc_terminate($app); proc_close($app);
    $cleanup();
}
$lulus = count(array_filter($RES, function ($r) { return $r['status'] === 'Lulus'; }));
printf("\nHASIL UAT: %d lulus, %d gagal dari %d skenario (%s)\n", $lulus, count($RES) - $lulus, count($RES), date('Y-m-d H:i'));
if ($OUT) file_put_contents($OUT, json_encode(['waktu' => date('Y-m-d H:i:s'), 'lulus' => $lulus, 'total' => count($RES), 'hasil' => $RES], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
if (count($RES) - $lulus) echo implode("\n", array_slice(file("$tmp/app.log", FILE_IGNORE_NEW_LINES) ?: [], -15)) . "\n";
exit($lulus === count($RES) ? 0 : 1);
