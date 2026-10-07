<?php
/**
 * Konfigurasi SNAP BI (BNI eCollection VA). Hanya mengembalikan array.
 *
 * Kredensial TIDAK ditaruh di sini, melainkan di application/config/snap_bi.local.php
 * (tidak di-commit; contoh: snap_bi.local.php.example). Isi lokal di-merge ke default.
 * Lokasi file lokal dapat dipindah lewat env SNAP_BI_LOCAL (mis. /etc/egzy/snap_bi.php).
 */
$__snap_local_file = getenv('SNAP_BI_LOCAL') ?: __DIR__ . '/snap_bi.local.php';
$__snap_local = is_file($__snap_local_file) ? (array) (require $__snap_local_file) : [];

return array_replace_recursive([
    // Masa berlaku token B2B yang kita terbitkan untuk bank (detik).
    'token_ttl' => 900,

    // Handler saat VA lunas, per purpose: ['file' => path, 'class' => nama] (method onPaid(array $va)).
    'handlers' => [
        'pbjt'    => ['file' => __DIR__ . '/../modules/pbjt/libraries/Pbjt_va_handler.php', 'class' => 'Pbjt_va_handler'],
        'booking' => ['file' => __DIR__ . '/../modules/snapbi/libraries/Snap_booking.php', 'class' => 'Booking_va_handler'],
    ],

    // --- Booking tamu: tiap hotel memakai kredensial BNI-nya sendiri. ---
    // Profil hotel = nama DB tenant (mis. 'tenant_wp001'); petakan di sini bila berbeda.
    'booking_profiles' => [],
    // payment_method_id "Virtual Account BNI" (migrations_pbjt/003).
    'booking_payment_method_id' => 20,
    // Masa aktif VA booking (jam).
    'booking_va_jam' => 24,

    // Profil kredensial yang dipakai Bapenda untuk menerbitkan VA tagihan PBJT.
    'pbjt_profile' => 'bapenda',

    // Tanggal jatuh tempo PBJT di bulan setelah masa. SESUAIKAN dengan Perda/Perkada.
    'pbjt_tanggal_jatuh_tempo' => 15,
    // Tagihan yang diterbitkan setelah jatuh tempo: VA aktif selama N hari sejak terbit.
    'pbjt_masa_aktif_va_terlambat' => 7,

    // Profil kredensial: satu per pemilik rekening (mis. 'bapenda', 'wp001').
    'profiles' => [],
], $__snap_local);
