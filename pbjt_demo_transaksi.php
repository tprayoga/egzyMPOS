<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // skrip CLI: tolak akses via web
$__db = require __DIR__ . '/application/config/pbjt_db.php'; // kredensial terpusat
/**
 * Demo: masukkan transaksi NYATA ke tabel aplikasi (booked_info + customer_order)
 * untuk masa 2026-08, lalu jalankan refreshLive dan buktikan /pbjt menangkapnya.
 * Semua transaksi aplikasi = omzet WP tenant-aplikasi (default WP002).
 *
 * Jalankan: php pbjt_demo_transaksi.php
 */
define('PBJT_CLI', true);
require __DIR__ . '/application/modules/pbjt/libraries/Pbjt_engine.php';

$masa = '2026-08';
$m = new mysqli($__db['host'], $__db['user'], $__db['pass'], 'hotelv3');
if ($m->connect_errno) { fwrite(STDERR, $m->connect_error); exit(1); }

// Bersihkan demo sebelumnya.
$m->query("DELETE FROM booked_info WHERE booking_number LIKE 'DEMO-%'");
$m->query("DELETE FROM customer_order WHERE saleinvoice LIKE 'DEMO-%'");

// --- Booking kamar (booked_info): 6 booking @ 25 jt = 150 jt ---
$st = $m->prepare("INSERT INTO booked_info
  (booking_number, date_time, roomid, room_no, roomrate, total_room, total_price, paid_amount,
   full_guest_name, checkindate, checkoutdate, cutomerid, bookingstatus)
  VALUES (?,?,?,?,?,1,?,?,?,?,?,1,'Checkout')");
$roomTotal = 0;
for ($i = 1; $i <= 6; $i++) {
    $day = 2 + $i * 4;                       // tersebar di bulan
    $tgl = sprintf('%s-%02d', $masa, $day);
    $bn = "DEMO-RM-$i";
    $rid = (string) (100 + $i); $rno = "R$i"; $rate = '2500000';
    $price = 25000000.00; $paid = 25000000.00;
    $guest = "Tamu Demo $i";
    $ci = "$tgl 14:00:00"; $co = sprintf('%s-%02d 12:00:00', $masa, $day + 2); $dt = "$tgl 10:00:00";
    $st->bind_param('sssssddsss', $bn, $dt, $rid, $rno, $rate, $price, $paid, $guest, $ci, $co);
    $st->execute();
    $roomTotal += 25000000;
}
$st->close();

// --- Order restoran (customer_order): 30 order @ 3 jt = 90 jt ---
$st = $m->prepare("INSERT INTO customer_order
  (saleinvoice, marge_order_id, customer_id, cutomertype, order_date, order_time, totalamount, customerpaid, order_status)
  VALUES (?, '', 1, 1, ?, '19:00:00', ?, ?, 1)");
$restoTotal = 0;
for ($i = 1; $i <= 30; $i++) {
    $day = ($i % 28) + 1;
    $tgl = sprintf('%s-%02d', $masa, $day);
    $inv = "DEMO-OR-$i";
    $amt = 3000000.00;
    $st->bind_param('ssdd', $inv, $tgl, $amt, $amt);
    $st->execute();
    $restoTotal += 3000000;
}
$st->close();

echo "Transaksi demo dimasukkan ke aplikasi:\n";
echo "  Booking kamar : 6 (" . number_format($roomTotal, 0, ',', '.') . ")\n";
echo "  Order resto   : 30 (" . number_format($restoTotal, 0, ',', '.') . ")\n";
echo "  Total omzet    : " . number_format($roomTotal + $restoTotal, 0, ',', '.') . "\n\n";

// --- Refresh live (sync dari aplikasi + rekonsiliasi) ---
$engine = new Pbjt_engine($m);
$wpApp = $engine->appTenant();
$sync = $engine->syncOmzetFromApp($masa);
$engine->reconcile($masa);

echo "refreshLive untuk $wpApp: {$sync['baris']} baris, omzet " . number_format($sync['omzet'], 0, ',', '.') . "\n\n";

// --- Bukti: yang dilihat WP002 (tenant-aplikasi) di /pbjt ---
$ctx = $engine->contextFromSession(3, 0); // user WP002
$kpi = $engine->kpi($ctx, $masa);
$temuan = $engine->temuan($ctx, $masa);
echo "Yang dilihat WP002 di /pbjt (live):\n";
echo "  Omzet tercatat (dari transaksi app) vs QRIS -> temuan: {$kpi['jml_temuan']}, potensi Rp " . number_format($kpi['total_potensi'], 0, ',', '.') . "\n";
foreach ($temuan as $t) {
    echo "  - {$t['nama']}: omzet lapor Rp " . number_format($t['omzet_terlapor'], 0, ',', '.')
       . " | QRIS Rp " . number_format($t['qris_teragregasi'], 0, ',', '.')
       . " | potensi Rp " . number_format($t['potensi_rp'], 0, ',', '.') . "\n";
}
$m->close();
