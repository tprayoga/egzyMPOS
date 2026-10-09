<?php
/**
 * Seed DATA DEMO EGZY — satu cerita utuh agar isi sistem mudah dipahami.
 *
 *   php seed_demo.php --yes                      backup semua DB, lalu bangun ulang data demo
 *   php seed_demo.php --yes --password='Rahasia#1' password akun demo (atau env EGZY_DEMO_PASSWORD)
 *   php seed_demo.php --yes --no-backup
 *
 * Tanpa --password / EGZY_DEMO_PASSWORD, password dibuat ACAK dan dicetak sekali di akhir.
 * Password sengaja tidak ditulis di kode/dokumen (repositori publik).
 *
 * Yang dibangun (deterministik: hasil sama setiap dijalankan):
 *  - Bapenda (DB oversight): 10 WP dengan "peran" berbeda, peta MID, omzet tapping box,
 *    settlement QRIS 3 bank, temuan rekonsiliasi, tagihan PBJT + VA (lunas/menunggu/terlambat).
 *  - Tiap tenant aplikasi: identitas hotel/resto (Rp, Asia/Jakarta), lantai & kamar, tamu,
 *    booking (selesai/check-in/akan datang/batal) + pembayaran, menu & order restoran.
 *  - Akun login yang jelas (lihat ringkasan di akhir). Salinan akun admin lama di DB tenant dibuang.
 *
 * HANYA untuk lingkungan demo/pengembangan: data operasional DB terkait DIGANTI.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "Data demo akan MENGGANTI isi DB oversight, kontrol, dan tenant terdaftar.\nJalankan: php seed_demo.php --yes\n");
    exit(1);
}
define('PBJT_CLI', 1);
define('BASEPATH', __DIR__ . '/system/');
$APP = __DIR__ . '/application/';
$C = require $APP . 'config/pbjt_db.php';
require $APP . 'helpers/egzy_auth_helper.php';
require $APP . 'modules/pbjt/libraries/Pbjt_engine.php';
require $APP . 'modules/pbjt/libraries/Pbjt_pajak.php';
require $APP . 'modules/pbjt/libraries/Pbjt_tagihan.php';
require $APP . 'modules/pbjt/libraries/Pbjt_backup.php';
require $APP . 'modules/snapbi/libraries/Snap_store.php';

$__pw = getopt('', ['password:', 'yes', 'no-backup']);
define('PASSWORD', !empty($__pw['password']) ? $__pw['password'] : (getenv('EGZY_DEMO_PASSWORD') ?: egzy_password_random(14)));
if ($err = egzy_password_policy_error(PASSWORD)) { fwrite(STDERR, "$err\n"); exit(1); }
const MULAI = '2026-08-01';
const MASA = ['2026-08', '2026-09'];
$TODAY = date('Y-m-d');
mt_srand(2026);

function db($name = '')
{
    global $C;
    $m = new mysqli($C['host'], $C['user'], $C['pass'], $name);
    $m->set_charset('utf8mb4');
    return $m;
}
function q(mysqli $m, $sql)
{
    if (!$m->query($sql)) throw new RuntimeException("SQL gagal di {$m->query('SELECT DATABASE()')->fetch_row()[0]}: {$m->error}\n$sql");
}
/** INSERT dari array asosiatif; mengembalikan insert_id. */
function ins(mysqli $m, $table, array $row)
{
    $cols = implode(',', array_map(function ($k) { return "`$k`"; }, array_keys($row)));
    $vals = implode(',', array_map(function ($v) use ($m) {
        return $v === null ? 'NULL' : "'" . $m->real_escape_string((string) $v) . "'";
    }, array_values($row)));
    q($m, "INSERT INTO `$table` ($cols) VALUES ($vals)");
    return $m->insert_id;
}
function kosongkan(mysqli $m, array $tables)
{
    q($m, 'SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) {
        if ($m->query("SHOW TABLES LIKE '$t'")->num_rows) q($m, "TRUNCATE TABLE `$t`");
    }
    q($m, 'SET FOREIGN_KEY_CHECKS=1');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function rnd($a, $b) { return mt_rand($a, $b); }
function pick(array $a) { return $a[mt_rand(0, count($a) - 1)]; }
function hari($from, $to)
{
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) $out[] = date('Y-m-d', $t);
    return $out;
}
function akhir_pekan($d) { return in_array(date('N', strtotime($d)), ['5', '6']); }
function rp($n) { return 'Rp ' . number_format($n, 0, ',', '.'); }

// ====================================================================== CERITA
// rasio_qris = settlement QRIS ÷ omzet tercatat. < 1 wajar (sebagian tamu bayar tunai);
// > 1 berarti ada penjualan non-tunai yang tidak tercatat -> temuan rekonsiliasi.
$WP = [
    'WP001' => ['nama' => 'Hotel Melati Indah', 'jenis' => 'hotel', 'kec' => 'Coblong', 'db' => 'tenant_wp001', 'email' => 'melati@egzy.test',
        'mid' => [['HTL001A', 'BNI', 1.0]], 'rasio' => ['2026-08' => 0.55, '2026-09' => 0.55, '2026-10' => 0.55],
        'hotel' => ['kamar' => 'standar', 'tamu_per_hari' => [1, 3]], 'resto' => [2, 5],
        'tagihan' => ['2026-08' => 'lunas', '2026-09' => 'lunas'],
        'cerita' => 'WP patuh pengguna aplikasi EGZY: semua transaksi tercatat, QRIS < omzet, pajak dibayar lewat VA.'],
    'WP002' => ['nama' => 'Hotel Serena', 'jenis' => 'hotel', 'kec' => 'Lengkong', 'db' => 'tenant_wp002', 'email' => 'serena@egzy.test',
        'mid' => [['HTL002A', 'BRI', 1.0]], 'rasio' => ['2026-08' => 1.15, '2026-09' => 1.35, '2026-10' => 1.3],
        'hotel' => ['kamar' => 'standar', 'tamu_per_hari' => [1, 3]], 'resto' => null,
        'tagihan' => ['2026-08' => 'lunas', '2026-09' => 'menunggu'],
        'cerita' => 'Kurang lapor: sebagian tamu tidak diinput ke aplikasi, QRIS bank melebihi omzet tercatat -> temuan risiko tinggi.'],
    'WP003' => ['nama' => 'Guest House Kenanga', 'jenis' => 'hotel', 'kec' => 'Coblong', 'db' => null, 'device' => 'TB-KNG-01', 'harian' => 2600000,
        'mid' => [['GH003A', 'bank bjb', 0.45], ['GH003B', 'BNI', 0.55]], 'rasio' => ['2026-08' => 1.1, '2026-09' => 1.2, '2026-10' => 1.2],
        'tagihan' => ['2026-08' => 'lunas', '2026-09' => 'menunggu'],
        'cerita' => 'Tapping box + multi-MID: QRIS dari 2 outlet; outlet kedua tidak terpasang tapping box -> temuan.'],
    'WP004' => ['nama' => 'Resto Sunda Asli', 'jenis' => 'resto', 'kec' => 'Cidadap', 'db' => null, 'device' => 'TB-RSA-01', 'harian' => 4200000,
        'mid' => [['RST004A', 'bank bjb', 1.0]], 'rasio' => ['2026-08' => 0.6, '2026-09' => 0.62, '2026-10' => 0.6],
        'tagihan' => ['2026-08' => 'lunas', '2026-09' => 'lunas'],
        'cerita' => 'Restoran patuh dengan tapping box; tagihan selalu lunas tepat waktu.'],
    'WP005' => ['nama' => 'Cafe Kopi Senja', 'jenis' => 'resto', 'kec' => 'Coblong', 'db' => null, 'device' => 'TB-KPS-01', 'harian' => 1500000,
        'mid' => [['RST005A', 'BRI', 1.0]], 'rasio' => ['2026-08' => 0.7, '2026-09' => 1.05, '2026-10' => 0.75],
        'tagihan' => ['2026-08' => 'lunas', '2026-09' => 'belum'],
        'cerita' => 'Selisih tipis di September (risiko sedang); QRIS outlet baru "Kopi Senja Dago" muncul di antrean MID tak terpetakan.'],
    'WP006' => ['nama' => 'Warung Steak Jaya', 'jenis' => 'resto', 'kec' => 'Lengkong', 'db' => null, 'device' => 'TB-WSJ-01', 'harian' => 2900000,
        'mid' => [['RST006A', 'BNI', 1.0]], 'rasio' => ['2026-08' => 0.65, '2026-09' => 0.65, '2026-10' => 0.65], 'refund' => true,
        'tagihan' => ['2026-08' => 'lunas', '2026-09' => 'belum'],
        'cerita' => 'Ada refund QRIS (nilai negatif) yang ikut dihitung; tetap patuh. Tagihan September belum diterbitkan.'],
    'WP007' => ['nama' => 'Villa Asri', 'jenis' => 'hotel', 'kec' => 'Cidadap', 'db' => 'tenant_wp007', 'email' => 'villa@egzy.test',
        'mid' => [], 'rasio' => [], 'hotel' => ['kamar' => 'villa', 'tamu_per_hari' => [0, 0], 'mulai' => '2026-10-05'], 'resto' => null,
        'tagihan' => [],
        'cerita' => 'Tenant baru (bergabung Oktober): kamar sudah disiapkan, baru ada booking ke depan; belum ada tagihan.'],
    'WP008' => ['nama' => 'Hotel Cempaka', 'jenis' => 'hotel', 'kec' => 'Sukajadi', 'db' => 'tenant_wp008', 'email' => 'cempaka@egzy.test',
        'mid' => [['HTL008A', 'BRI', 1.0]], 'rasio' => ['2026-08' => 0.5, '2026-09' => 0.5, '2026-10' => 0.5],
        'hotel' => ['kamar' => 'standar', 'tamu_per_hari' => [0, 2]], 'resto' => null,
        'tagihan' => ['2026-08' => 'terlambat', '2026-09' => 'menunggu'],
        'cerita' => 'Patuh melapor tetapi telat bayar: VA Agustus kedaluwarsa tanpa pembayaran.'],
    'WP009' => ['nama' => 'Resto Bahari', 'jenis' => 'resto', 'kec' => 'Bojongloa', 'db' => 'tenant_wp009', 'email' => 'bahari@egzy.test',
        'mid' => [['RST009A', 'bank bjb', 1.0]], 'rasio' => ['2026-08' => 0.6, '2026-09' => 0.58, '2026-10' => 0.6],
        'hotel' => null, 'resto' => [6, 12],
        'tagihan' => ['2026-08' => 'lunas', '2026-09' => 'menunggu'],
        'cerita' => 'Restoran pengguna POS EGZY: omzet dari order kasir, patuh.'],
    'WP010' => ['nama' => 'Hotel Nirwana', 'jenis' => 'hotel', 'kec' => 'Andir', 'db' => 'tenant_wp010', 'email' => 'nirwana@egzy.test',
        'mid' => [['HTL010A', 'BNI', 1.0]], 'rasio' => ['2026-08' => 0.5, '2026-09' => 0.52, '2026-10' => 0.5],
        'hotel' => ['kamar' => 'standar', 'tamu_per_hari' => [1, 2]], 'resto' => [1, 3], 'booking_va' => true,
        'tagihan' => ['2026-08' => 'lunas', '2026-09' => 'lunas'],
        'cerita' => 'Hotel yang menerima pembayaran tamu via VA BNI (kredensial BNI milik hotel).'],
];
$UNMAPPED = ['mid' => 'QR-SNJ-02', 'bank' => 'bank bjb', 'mulai' => '2026-09-08', 'harian' => [180000, 420000]];

$NAMA_DEPAN = ['Budi', 'Siti', 'Andi', 'Dewi', 'Rizky', 'Putri', 'Agus', 'Rina', 'Dimas', 'Ayu', 'Fajar', 'Nadia', 'Hendra', 'Lestari', 'Yoga',
    'Intan', 'Arif', 'Maya', 'Bayu', 'Sri', 'Teguh', 'Wulan', 'Eko', 'Fitri', 'Gilang', 'Hana', 'Irfan', 'Jihan', 'Kevin', 'Laras'];
$NAMA_BELAKANG = ['Santoso', 'Wijaya', 'Pratama', 'Lestari', 'Saputra', 'Hidayat', 'Kusuma', 'Nugraha', 'Permana', 'Rahmawati', 'Setiawan', 'Gunawan', 'Siregar', 'Halim'];
$KOTA = ['Jakarta', 'Bandung', 'Surabaya', 'Bekasi', 'Bogor', 'Cirebon', 'Semarang', 'Yogyakarta', 'Depok', 'Tasikmalaya'];

$KAMAR = [
    'standar' => [
        ['tipe' => 'Superior Twin', 'rate' => 550000, 'bed' => 13, 'beds' => 2, 'cap' => 2, 'size' => 22, 'lantai' => 1, 'nomor' => [101, 102, 103, 104, 105, 106]],
        ['tipe' => 'Deluxe King', 'rate' => 780000, 'bed' => 16, 'beds' => 1, 'cap' => 2, 'size' => 28, 'lantai' => 2, 'nomor' => [201, 202, 203, 204, 205]],
        ['tipe' => 'Family Suite', 'rate' => 1250000, 'bed' => 14, 'beds' => 2, 'cap' => 4, 'size' => 42, 'lantai' => 3, 'nomor' => [301, 302]],
    ],
    'villa' => [
        ['tipe' => 'Villa 2 Kamar', 'rate' => 1900000, 'bed' => 14, 'beds' => 2, 'cap' => 4, 'size' => 90, 'lantai' => 1, 'nomor' => [1, 2, 3]],
        ['tipe' => 'Villa 3 Kamar + Kolam', 'rate' => 2750000, 'bed' => 16, 'beds' => 3, 'cap' => 6, 'size' => 140, 'lantai' => 1, 'nomor' => [4, 5]],
    ],
];
$MENU = [
    'Makanan' => [['Nasi Goreng Kampung', 38000], ['Mie Kocok Bandung', 35000], ['Sop Buntut', 72000], ['Ayam Bakar Madu', 48000],
                  ['Gurame Bakar', 95000], ['Nasi Timbel Komplit', 52000], ['Iga Bakar', 85000], ['Cumi Saus Padang', 68000]],
    'Minuman' => [['Es Teh Manis', 12000], ['Es Jeruk', 15000], ['Kopi Tubruk', 18000], ['Jus Alpukat', 25000], ['Bandrek', 20000], ['Air Mineral', 8000]],
    'Camilan' => [['Pisang Goreng Keju', 25000], ['Cireng Rujak', 22000], ['Batagor', 28000], ['Kentang Goreng', 27000]],
];

// ====================================================================== 0. BACKUP
$root = db();
if (!in_array('--no-backup', $argv, true)) {
    $dir = $C['backup_dir'] . '/sebelum-seed_' . date('Ymd_His');
    @mkdir($dir, 0700, true);
    $bk = new Pbjt_backup($root);
    $targets = array_merge([$C['oversight_db'], $C['control_db'], 'tenant_template'], array_filter(array_column($WP, 'db')));
    foreach ($targets as $d) {
        if ($root->query("SHOW DATABASES LIKE '$d'")->num_rows) $bk->dump($d, $dir);
    }
    echo "Backup sebelum seed: $dir\n";
}

// ====================================================================== 1. TENANT
$hashPw = egzy_password_hash(PASSWORD);
$omzetApp = []; // wp => tanggal => omzet tercatat (untuk membentuk settlement QRIS)
$bookingVa = []; // booking yang dibayar/menunggu via VA (WP010)

function seed_identitas(mysqli $t, array $w)
{
    $idr = $t->query("SELECT currencyid FROM currency WHERE currencyname='IDR'")->fetch_row();
    $cur = $idr ? (int) $idr[0] : ins($t, 'currency', ['currencyname' => 'IDR', 'curr_icon' => 'Rp', 'position' => 1, 'curr_rate' => '1.00']);
    $alamat = ['Coblong' => 'Jl. Ir. H. Juanda No. 112', 'Lengkong' => 'Jl. Buah Batu No. 45', 'Cidadap' => 'Jl. Setiabudi No. 210',
        'Sukajadi' => 'Jl. Sukajadi No. 88', 'Bojongloa' => 'Jl. Kopo No. 301', 'Andir' => 'Jl. Pajajaran No. 17'][$w['kec']] . ', Kec. ' . $w['kec'] . ', Kota Bandung';
    $st = $t->prepare("UPDATE setting SET storename=?, address=?, email=?, phone=?, currency=?, timezone='Asia/Jakarta', country='Indonesia',
        checkintime='14:00:00', checkouttime='12:00:00'");
    $phone = '022-' . rnd(2000000, 2999999);
    $st->bind_param('ssssi', $w['nama'], $alamat, $w['email'], $phone, $cur);
    $st->execute();
    $st = $t->prepare("UPDATE common_setting SET address=?, email=?, phone=?");
    $st->bind_param('sss', $alamat, $w['email'], $phone);
    $st->execute();
}

function seed_akun(mysqli $t, array $w, $hashPw)
{
    // Hanya admin milik WP ini; salinan akun lain (mis. admin Bapenda dari kloning) dibuang.
    q($t, "DELETE FROM user");
    q($t, "ALTER TABLE user AUTO_INCREMENT=1");
    ins($t, 'user', ['firstname' => 'Admin', 'lastname' => $w['nama'], 'email' => $w['email'], 'password' => $hashPw,
        'status' => 1, 'usertype' => 1, 'is_admin' => 1]);
}

function seed_kamar(mysqli $t, array $tipe)
{
    $lantai = [];
    $rooms = [];
    foreach ($tipe as $k) {
        if (!isset($lantai[$k['lantai']])) $lantai[$k['lantai']] = ins($t, 'tbl_floor', ['floorname' => 'Lantai ' . $k['lantai'], 'status' => 1]);
        $fid = $lantai[$k['lantai']];
        $rid = ins($t, 'roomdetails', ['roomtype' => $k['tipe'], 'roomsize' => $k['size'], 'roomsizemesurement' => '10', 'roomactive' => 1,
            'bedsno' => $k['beds'], 'bedstype' => $k['bed'], 'number_of_star' => 4, 'capacity' => $k['cap'], 'exbedcapability' => 1,
            'child_limit' => 2, 'rate' => $k['rate'], 'bedcharge' => 150000, 'personcharge' => 100000, 'roomstatus' => 1,
            'roomdescription' => "{$k['tipe']} {$k['size']} m², kapasitas {$k['cap']} orang, sarapan untuk 2 orang.",
            'reservecondition' => 'Check-in 14.00, check-out 12.00. Pembatalan gratis hingga H-1.']);
        foreach ($k['nomor'] as $no) {
            ins($t, 'tbl_floorplan', ['floorName' => $fid, 'noofroom' => count($k['nomor']), 'startno' => $k['nomor'][0], 'roomno' => $no]);
            ins($t, 'tbl_roomnofloorassign', ['roomid' => $rid, 'floorid' => $fid, 'roomno' => $no]);
        }
        $rooms[] = ['id' => $rid, 'rate' => $k['rate'], 'nomor' => $k['nomor'], 'tipe' => $k['tipe'], 'cap' => $k['cap']];
    }
    return $rooms;
}

function seed_tamu(mysqli $t, $n, $hashPw, $wpKey)
{
    global $NAMA_DEPAN, $NAMA_BELAKANG, $KOTA;
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $f = pick($NAMA_DEPAN); $l = pick($NAMA_BELAKANG);
        // Dua tamu pertama bisa login di website hotel (password demo); sisanya password acak.
        $email = $i <= 2 ? "tamu$i." . strtolower($wpKey) . '@egzy.test' : strtolower("$f.$l$i") . '@contoh.id';
        $ids[] = ['id' => ins($t, 'customerinfo', ['customernumber' => sprintf('%04d', $i), 'firstname' => $f, 'lastname' => $l, 'email' => $email,
            'cust_phone' => '08' . rnd(11, 59) . rnd(10000000, 99999999), 'address' => pick($KOTA), 'city' => pick($KOTA), 'country' => 'Indonesia',
            'nationality' => 'Indonesia', 'gender' => pick(['Laki-laki', 'Perempuan']), 'pass' => $i <= 2 ? $hashPw : egzy_password_hash(egzy_password_random()),
            'balance' => 0, 'active' => 1, 'signupdate' => date('Y-m-d', strtotime(MULAI) - rnd(0, 200) * 86400)]), 'nama' => "$f $l"];
    }
    return $ids;
}

function seed_booking(mysqli $t, $wpKey, array $w, array $rooms, array $tamu, $today, array &$omzet, array &$bookingVa)
{
    $h = $w['hotel'];
    $terisi = []; // roomno => [tgl => true]
    $no = 0; $inv = 0;
    $akhir = date('Y-m-d', strtotime($today . ' +14 days'));
    $mulai = isset($h['mulai']) ? $h['mulai'] : MULAI;
    foreach (hari($mulai, $akhir) as $d) {
        $n = rnd($h['tamu_per_hari'][0], $h['tamu_per_hari'][1]) + (akhir_pekan($d) ? 1 : 0);
        if ($wpKey === 'WP007') $n = in_array($d, ['2026-10-10', '2026-10-17']) ? 1 : 0; // tenant baru: 2 booking ke depan
        for ($i = 0; $i < $n; $i++) {
            $room = $rooms[mt_rand(0, 9) < 6 ? 0 : (mt_rand(0, 9) < 8 ? min(1, count($rooms) - 1) : count($rooms) - 1)];
            $malam = rnd(1, 3);
            $tgl = hari($d, date('Y-m-d', strtotime("$d +" . ($malam - 1) . ' days')));
            $nomor = null;
            foreach ($room['nomor'] as $nr) {
                $bebas = true;
                foreach ($tgl as $x) if (isset($terisi[$nr][$x])) { $bebas = false; break; }
                if ($bebas) { $nomor = $nr; break; }
            }
            if ($nomor === null) continue; // penuh
            foreach ($tgl as $x) $terisi[$nomor][$x] = true;

            $checkout = date('Y-m-d', strtotime("$d +$malam days"));
            $total = $room['rate'] * $malam;
            $g = pick($tamu);
            if ($checkout <= $today) $status = mt_rand(1, 100) <= 6 ? 1 : 5;
            elseif ($d <= $today) $status = 4;
            else $status = 0;
            $dibuat = $d <= $today ? $d : date('Y-m-d', strtotime($today . ' -' . rnd(0, 6) . ' days'));
            $viaVa = !empty($w['booking_va']) && mt_rand(1, 100) <= 40 && $status !== 1;
            $metode = $viaVa ? 'Virtual Account BNI' : pick(['Cash Payment', 'Cash Payment', 'Bank Payment', 'Card Payment']);
            $paid = $status === 5 ? $total : ($status === 4 ? (int) round($total / 2, -3) : ($status === 0 && $viaVa ? $total : 0));
            $no++;
            $bn = sprintf('%08d', $no);
            $bid = ins($t, 'booked_info', ['booking_number' => $bn, 'date_time' => "$dibuat " . sprintf('%02d:%02d:00', rnd(8, 21), rnd(0, 59)),
                'roomid' => $room['id'], 'nuofpeople' => min(2, $room['cap']), 'children' => mt_rand(0, 3) === 0 ? 1 : 0, 'total_room' => 1,
                'room_no' => $nomor, 'roomrate' => $room['rate'], 'total_price' => $room['rate'] /* app: harga per malam */, 'paid_amount' => $paid, 'offer_discount' => 0,
                'full_guest_name' => $g['nama'], 'checkindate' => "$d 14:00:00", 'checkoutdate' => "$checkout 12:00:00",
                'cutomerid' => $g['id'], 'bookingstatus' => $status, 'isSeen' => 1,
                'coments' => $status === 1 ? 'Dibatalkan tamu' : ($viaVa ? 'Booking website, dibayar via VA BNI' : 'Booking dari admin')]);
            ins($t, 'booked_details', ['bookedid' => $bid, 'booking_type' => '', 'booking_source' => $viaVa ? 'Website' : pick(['Walk-in', 'Telepon', 'Website', 'OTA']),
                'arival_from' => pick(['Jakarta', 'Bandung', 'Surabaya', 'Bekasi']), 'purpose' => pick(['Liburan', 'Bisnis', 'Keluarga']),
                'extra_facility_days' => '0', 'extrabed' => '0', 'extraperson' => '0', 'extrachild' => '0', 'complementary' => 'no',
                'complementaryprice' => '0', 'discountamount' => '0', 'commissionpersent' => '0', 'commissionamount' => '0',
                'payment_method' => $paid > 0 ? $metode : '', 'advance_amount' => $paid, 'booked_from' => $viaVa ? 1 : 0]);
            if ($paid > 0) {
                $inv++;
                ins($t, 'tbl_guestpayments', ['bookedid' => $bid, 'invoice' => sprintf('%06d', $inv), 'paydate' => $dibuat, 'paymenttype' => $metode,
                    'paymentamount' => $paid, 'details' => $viaVa ? 'BNI VA (demo)' : 'Pembayaran tamu', 'book_type' => 0]);
                q($t, "UPDATE customerinfo SET balance = balance + $paid WHERE customerid = {$g['id']}");
            }
            if ($viaVa) $bookingVa[] = ['wp' => $wpKey, 'db' => $w['db'], 'bn' => $bn, 'nama' => $g['nama'], 'total' => $total, 'paid' => $paid > 0, 'tgl' => $dibuat];
            if ($status !== 1) $omzet[$dibuat] = ($omzet[$dibuat] ?? 0) + $total;
        }
    }
    return $no;
}

function seed_resto(mysqli $t, array $range, $today, array &$omzet)
{
    global $MENU;
    $kitchen = ins($t, 'tbl_kitchen', ['kitchen_name' => 'Dapur Utama', 'status' => 1]);
    $items = [];
    $pos = 0;
    foreach ($MENU as $kat => $list) {
        $cid = ins($t, 'item_category', ['Name' => $kat, 'Position' => ++$pos, 'CategoryIsActive' => 1, 'parentid' => 0, 'DateInserted' => MULAI]);
        foreach ($list as $i => $m) {
            $pid = ins($t, 'item_foods', ['CategoryID' => $cid, 'ProductName' => $m[0], 'bigthumb' => '', 'medium_thumb' => '', 'small_thumb' => '',
                'descrip' => $m[0], 'kitchenid' => $kitchen, 'Position' => $i + 1, 'ProductsIsActive' => 1, 'productvat' => 0, 'DateInserted' => MULAI]);
            $vid = ins($t, 'variant', ['menuid' => $pid, 'variantName' => 'Reguler', 'price' => $m[1]]);
            $items[] = ['pid' => $pid, 'vid' => $vid, 'harga' => $m[1], 'kat' => $kat];
        }
    }
    for ($i = 1; $i <= 10; $i++) ins($t, 'rest_table', ['tablename' => "Meja $i", 'person_capicity' => $i <= 6 ? 4 : 6, 'table_icon' => '', 'status' => 0]);
    $walkin = ins($t, 'customerinfo', ['customernumber' => '9999', 'firstname' => 'Tamu', 'lastname' => 'Restoran', 'email' => 'walkin@contoh.id',
        'pass' => egzy_password_hash(egzy_password_random()), 'balance' => 0, 'active' => 1, 'signupdate' => MULAI]);

    $n = 0;
    foreach (hari(MULAI, $today) as $d) {
        $jml = rnd($range[0], $range[1]) + (akhir_pekan($d) ? 2 : 0);
        for ($o = 0; $o < $jml; $o++) {
            $n++;
            $baris = [];
            $total = 0;
            for ($k = 0; $k < rnd(1, 4); $k++) {
                $it = pick($items);
                $qty = $it['kat'] === 'Minuman' ? rnd(1, 3) : rnd(1, 2);
                $baris[] = [$it, $qty];
                $total += $it['harga'] * $qty;
            }
            $status = $d < $today ? (mt_rand(1, 100) <= 3 ? 5 : 4) : pick([1, 2, 4]);
            // Engine PBJT yang sama dengan POS: harga menu belum termasuk PBJT (default restoran).
            $subtotal = $total;
            $pj = Pbjt_pajak::hitung($subtotal, 0, Pbjt_pajak::tarif(), false);
            $total = (int) round($pj['total']);
            $oid = ins($t, 'customer_order', ['saleinvoice' => sprintf('%06d', $n), 'customer_id' => $walkin, 'cutomertype' => 1,
                'order_date' => $d, 'order_time' => sprintf('%02d:%02d:00', rnd(10, 21), rnd(0, 59)), 'table_no' => rnd(1, 10),
                'totalamount' => $total, 'customerpaid' => $status === 4 ? $total : 0, 'order_status' => $status, 'tokenno' => $n]);
            foreach ($baris as $b) {
                ins($t, 'order_menu', ['order_id' => $oid, 'menu_id' => $b[0]['pid'], 'price' => $b[0]['harga'], 'menuqty' => $b[1],
                    'add_on_id' => '', 'addonsqty' => '', 'varientid' => $b[0]['vid'], 'food_status' => $status === 4 ? 1 : 0]);
            }
            if ($status !== 5) { // POS membuat bill saat order; lunas bila selesai
                ins($t, 'bill', ['customer_id' => $walkin, 'order_id' => $oid, 'total_amount' => $subtotal, 'discount' => 0, 'service_charge' => 0,
                    'VAT' => $pj['pbjt'], 'bill_amount' => $total, 'bill_date' => $d, 'bill_time' => '20:00:00', 'bill_status' => $status === 4 ? 1 : 0,
                    'payment_method_id' => pick([4, 4, 6, 1]), 'create_by' => 1, 'create_date' => $d, 'update_by' => 1, 'update_date' => $d]);
            }
            if ($status !== 5) $omzet[$d] = ($omzet[$d] ?? 0) + $total;
        }
    }
    return $n;
}

$OPERASIONAL = ['booked_info', 'booked_details', 'tbl_guestpayments', 'tbl_otherguest', 'customerinfo', 'customer_order', 'order_menu', 'bill',
    'tbl_kitchen_order', 'item_foods', 'variant', 'item_category', 'tbl_kitchen', 'rest_table', 'roomdetails', 'tbl_floor', 'tbl_floorplan',
    'tbl_roomnofloorassign', 'room_image', 'acc_transaction'];

echo "\n1. Tenant aplikasi\n";
foreach ($WP as $key => $w) {
    if (!$w['db']) continue;
    if (!$root->query("SHOW DATABASES LIKE '{$w['db']}'")->num_rows) {
        echo "   ! {$w['db']} tidak ada — lewati $key (buat lewat /pbjt/daftar dulu)\n";
        continue;
    }
    $t = db($w['db']);
    kosongkan($t, $OPERASIONAL);
    seed_identitas($t, $w);
    seed_akun($t, $w, $hashPw);
    $omzetApp[$key] = [];
    $info = [];
    if (!empty($w['hotel'])) {
        $rooms = seed_kamar($t, $KAMAR[$w['hotel']['kamar']]);
        $tamu = seed_tamu($t, $key === 'WP007' ? 4 : 40, $hashPw, $key);
        $info[] = count(array_merge(...array_column($rooms, 'nomor'))) . ' kamar';
        $info[] = seed_booking($t, $key, $w, $rooms, $tamu, $TODAY, $omzetApp[$key], $bookingVa) . ' booking';
    } elseif ($key === 'WP009') {
        seed_tamu($t, 2, $hashPw, $key);
    }
    if (!empty($w['resto'])) $info[] = seed_resto($t, $w['resto'], $TODAY, $omzetApp[$key]) . ' order resto';
    printf("   %s %-20s %s\n", $key, $w['nama'], implode(', ', $info));
    $t->close();
}

// Template tenant baru: jangan mewarisi akun siapa pun.
$tpl = db('tenant_template');
q($tpl, "DELETE FROM user");
seed_identitas($tpl, ['nama' => 'Usaha Baru', 'kec' => 'Coblong', 'email' => 'admin@usaha-baru.test']);
$tpl->close();

// ====================================================================== 2. KONTROL & AKUN
echo "\n2. Registry tenant & akun login\n";
$ctl = db($C['control_db']);
kosongkan($ctl, ['snap_va', 'snap_log', 'snap_seen', 'snap_token_in', 'snap_token_out', 'directory']);
ins($ctl, 'directory', ['email' => 'bapenda@egzy.test', 'wp_id' => null, 'db_name' => $C['oversight_db'], 'is_bapenda' => 1]);
foreach ($WP as $key => $w) {
    if (!$w['db']) continue;
    $st = $ctl->prepare("UPDATE tenants SET nama=?, jenis_usaha=?, kecamatan=? WHERE wp_id=?");
    $st->bind_param('ssss', $w['nama'], $w['jenis'], $w['kec'], $key);
    $st->execute();
    ins($ctl, 'directory', ['email' => $w['email'], 'wp_id' => $key, 'db_name' => $w['db'], 'is_bapenda' => 0]);
}

// ====================================================================== 3. OVERSIGHT (BAPENDA)
echo "\n3. Data pengawasan Bapenda\n";
$ov = db($C['oversight_db']);
kosongkan($ov, array_merge(['pbjt_wp', 'pbjt_peta_mid', 'pbjt_omzet_harian', 'pbjt_settlement_harian', 'pbjt_temuan', 'pbjt_mid_unmapped',
    'pbjt_tagihan', 'pbjt_audit_log'], $OPERASIONAL));
seed_identitas($ov, ['nama' => 'Bapenda Kota Bandung — Pengawasan PBJT', 'kec' => 'Coblong', 'email' => 'bapenda@egzy.test']);
// Akun Bapenda (id 1). Akun lama id 2/3 dipertahankan sebagai fixture uji isolasi, tetapi tidak bisa login.
q($ov, "DELETE FROM user WHERE id NOT IN (1,2,3)");
$st = $ov->prepare("UPDATE user SET firstname='Petugas', lastname='Bapenda', email='bapenda@egzy.test', password=?, is_admin=1, usertype=1, status=1 WHERE id=1");
$st->bind_param('s', $hashPw);
$st->execute();
foreach ([2 => 'WP001', 3 => 'WP002'] as $uid => $wp) {
    $mati = egzy_password_hash(egzy_password_random(24));
    $st = $ov->prepare("UPDATE user SET email=?, password=?, is_admin=0, status=0 WHERE id=?");
    $em = "fixture-uji-$uid@egzy.invalid";
    $st->bind_param('ssi', $em, $mati, $uid);
    $st->execute();
}

$hariSampai = date('Y-m-d', strtotime("$TODAY -1 day"));
$nSet = 0;
foreach ($WP as $key => $w) {
    ins($ov, 'pbjt_wp', ['wp_id' => $key, 'nama' => $w['nama'], 'jenis_usaha' => $w['jenis'], 'kecamatan' => $w['kec']]);
    foreach ($w['mid'] as $m) ins($ov, 'pbjt_peta_mid', ['mid' => $m[0], 'wp_id' => $key, 'device_id' => $w['device'] ?? 'APP']);

    // Omzet harian: tapping box (WP tanpa aplikasi) atau hasil transaksi aplikasi.
    if (!$w['db']) {
        $omzet = [];
        foreach (hari(MULAI, $hariSampai) as $d) {
            $v = (int) round($w['harian'] * (akhir_pekan($d) ? 1.35 : 1.0) * (rnd(80, 120) / 100), -3);
            $omzet[$d] = $v;
            ins($ov, 'pbjt_omzet_harian', ['wp_id' => $key, 'device_id' => $w['device'], 'tanggal' => $d, 'omzet_bruto' => $v, 'pajak_terlapor' => (int) round($v * 0.10)]);
        }
    } else {
        $omzet = $omzetApp[$key] ?? [];
    }
    // Settlement QRIS per MID per hari, mengikuti rasio cerita.
    foreach ($omzet as $d => $v) {
        if ($d > $hariSampai || !isset($w['rasio'][substr($d, 0, 7)])) continue;
        foreach ($w['mid'] as $m) {
            $nilai = (int) round($v * $w['rasio'][substr($d, 0, 7)] * $m[2] * (rnd(90, 110) / 100), -3);
            if ($nilai <= 0) continue;
            ins($ov, 'pbjt_settlement_harian', ['mid' => $m[0], 'bank' => $m[1], 'kanal' => 'QRIS', 'tanggal_transaksi' => $d, 'nilai_settlement' => $nilai, 'status' => 'sukses']);
            $nSet++;
            if (!empty($w['refund']) && mt_rand(1, 100) <= 12) {
                ins($ov, 'pbjt_settlement_harian', ['mid' => $m[0], 'bank' => $m[1], 'kanal' => 'QRIS', 'tanggal_transaksi' => $d,
                    'nilai_settlement' => -(int) round($nilai * rnd(10, 30) / 100, -3), 'status' => 'refund']);
                $nSet++;
            }
        }
    }
}
foreach (hari($UNMAPPED['mulai'], $hariSampai) as $d) {
    ins($ov, 'pbjt_settlement_harian', ['mid' => $UNMAPPED['mid'], 'bank' => $UNMAPPED['bank'], 'kanal' => 'QRIS', 'tanggal_transaksi' => $d,
        'nilai_settlement' => (int) round(rnd($UNMAPPED['harian'][0], $UNMAPPED['harian'][1]), -3), 'status' => 'sukses']);
    $nSet++;
}
echo "   10 WP, " . $ov->query("SELECT COUNT(*) FROM pbjt_peta_mid")->fetch_row()[0] . " MID, $nSet baris settlement QRIS\n";

// Rekonsiliasi lewat mesin yang sama dengan aplikasi (omzet aplikasi ditarik dari DB tenant).
$engine = new Pbjt_engine($ov, ['oversight_db' => $C['oversight_db'], 'control_db' => $C['control_db']]);
$nTemuan = $engine->refreshLiveAll();
echo "   Rekonsiliasi: $nTemuan temuan di " . implode(', ', $engine->liveMasaList()) . "\n";

// ====================================================================== 4. TAGIHAN + VA
echo "\n4. Tagihan PBJT & VA\n";
$store = new Snap_store($ctl);
$tag = new Pbjt_tagihan($ov, ['tanggal_jatuh_tempo' => 10]);
$ringkas = [];
foreach ($WP as $key => $w) {
    foreach ($w['tagihan'] as $masa => $nasib) {
        if ($nasib === 'belum') { $ringkas[] = "$key $masa belum terbit"; continue; }
        $pajak = $tag->pajakTerutang($key, $masa);
        if ($pajak <= 0) continue;
        $jt = $tag->jatuhTempo($masa);
        $terbit = date('Y-m-d H:i:s', strtotime(substr($jt, 0, 7) . '-02 09:00'));
        $vaExp = $nasib === 'menunggu' ? max("$jt 23:59:59", date('Y-m-d 23:59:59', strtotime("$TODAY +7 days"))) : "$jt 23:59:59";
        $va = $store->insertVa(['profile' => 'bapenda', 'purpose' => 'pbjt', 'tenant_db' => null, 'ref' => 'TMP', 'partner_service_id' => '   98829',
            'va_name' => 'PBJT ' . $w['nama'], 'amount' => $pajak, 'currency' => 'IDR', 'expired_at' => $vaExp, 'trx_prefix' => 'PBJT'], 8);
        $lunas = $nasib === 'lunas' ? date('Y-m-d H:i:s', strtotime(substr($jt, 0, 7) . '-' . sprintf('%02d', rnd(3, 9)) . ' ' . rnd(9, 16) . ':' . rnd(10, 59) . ':00')) : null;
        $tid = ins($ov, 'pbjt_tagihan', ['wp_id' => $key, 'masa' => $masa, 'pajak' => $pajak, 'status' => $lunas ? 'lunas' : 'terbit',
            'jatuh_tempo' => $jt, 'snap_va_id' => $va['id'], 'va_no' => trim($va['va_no']), 'dibayar' => $lunas ? $pajak : null, 'lunas_pada' => $lunas,
            'dibuat_oleh' => 'bapenda#1', 'dibuat_pada' => $terbit, 'diubah_pada' => $lunas ?: $terbit]);
        q($ctl, "UPDATE snap_va SET ref='$tid', status='" . ($lunas ? 'paid' : 'pending') . "', created_at='$terbit', updated_at='" . ($lunas ?: $terbit) . "'"
            . ($lunas ? ", paid_at='$lunas', paid_amount=$pajak, payment_request_id='DEMO-$key-$masa', dispatched_at='$lunas'" : '') . " WHERE id={$va['id']}");
        ins($ov, 'pbjt_audit_log', ['waktu' => $terbit, 'aktor' => 'bapenda#1', 'aksi' => 'terbit-tagihan', 'detail' => "$key masa $masa " . rp($pajak) . ' VA ' . trim($va['va_no'])]);
        if ($lunas) ins($ov, 'pbjt_audit_log', ['waktu' => $lunas, 'aktor' => 'bank#bapenda', 'aksi' => 'tagihan-lunas', 'detail' => "$key masa $masa " . rp($pajak)]);
        $ringkas[] = sprintf('%s %s %-9s %s', $key, $masa, $nasib, rp($pajak));
    }
}
foreach ($ringkas as $r) echo "   $r\n";

// VA pembayaran booking tamu (WP010, kredensial BNI milik hotel).
foreach ($bookingVa as $b) {
    $va = $store->insertVa(['profile' => $b['db'], 'purpose' => 'booking', 'tenant_db' => $b['db'], 'ref' => $b['bn'], 'partner_service_id' => '   88110',
        'va_name' => $b['nama'], 'amount' => $b['total'], 'currency' => 'IDR', 'expired_at' => date('Y-m-d H:i:s', strtotime($b['tgl'] . ' +1 day 10:00')), 'trx_prefix' => 'BK'], 8);
    $paidAt = $b['tgl'] . ' ' . sprintf('%02d:%02d:00', rnd(9, 20), rnd(0, 59));
    q($ctl, "UPDATE snap_va SET status='" . ($b['paid'] ? 'paid' : 'pending') . "', created_at='{$b['tgl']} 08:00:00'"
        . ($b['paid'] ? ", paid_at='$paidAt', paid_amount={$b['total']}, payment_request_id='DEMO-{$b['bn']}', dispatched_at='$paidAt'" : '') . " WHERE id={$va['id']}");
}
echo "   " . count($bookingVa) . " VA booking tamu (Hotel Nirwana)\n";
ins($ov, 'pbjt_audit_log', ['waktu' => date('Y-m-d H:i:s'), 'aktor' => 'sistem', 'aksi' => 'seed-demo', 'detail' => 'Data demo dibangun ulang oleh seed_demo.php']);

// ====================================================================== RINGKASAN
echo "\n================ AKUN DEMO (password semua: " . PASSWORD . ") ================\n";
printf("  %-26s %s\n", 'bapenda@egzy.test', 'Bapenda — dasbor pengawasan semua WP, terbitkan tagihan, kelola tenant');
foreach ($WP as $key => $w) {
    if ($w['db']) printf("  %-26s %s %s — %s\n", $w['email'], $key, $w['nama'], $w['cerita']);
}
echo "  Tamu website: tamu1.wp001@egzy.test, tamu1.wp010@egzy.test (dst. tamu2.*)\n";
echo "  WP tanpa aplikasi (hanya tapping box, dilihat dari akun Bapenda): WP003–WP006\n";
echo "\nSelesai. Buka /pbjt sebagai Bapenda dan pilih masa 2026-08 / 2026-09.\n";
