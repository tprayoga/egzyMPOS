<?php
/**
 * Konfigurasi PBJT dari Pemda — BUKAN pengaturan merchant (read-only bagi WP).
 * Satu-satunya sumber tarif PBJT untuk kamar & restoran (lihat Pbjt_pajak).
 * Mengubah tarif tidak mengubah transaksi lama (tarif_snapshot per transaksi).
 */
return [
    'tarif_pbjt' => 0.10,
];
