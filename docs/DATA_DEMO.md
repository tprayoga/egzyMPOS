# Data Demo EGZY

Data demo dibuat oleh `php seed_demo.php --yes` (backup otomatis dibuat lebih dulu di folder backup).
Hasilnya selalu sama setiap dijalankan, jadi aman untuk mengulang demo dari awal.

Periode data: **1 Agustus 2026 s.d. 2 minggu ke depan**. Masa pajak yang sudah lengkap: **Agustus** dan **September 2026**; Oktober masih berjalan.

## Akun login

Semua akun memakai **satu password demo** yang ditentukan saat seed (`--password=...` atau env `EGZY_DEMO_PASSWORD`); tanpa itu password dibuat acak dan **dicetak sekali** di akhir output `seed_demo.php`. Password tidak pernah ditulis di repositori.
Login di `/login`; sistem otomatis membuka database milik akun tersebut.

Di server yang sudah berjalan, ganti semua password akun demo dengan `sudo -u www-data php pbjt_rotate_passwords.php` (password baru acak per akun, dicetak sekali).

| Email | Peran | Apa yang terlihat |
| --- | --- | --- |
| `bapenda@egzy.test` | Petugas Bapenda | Semua WP: rekonsiliasi, temuan, tagihan & VA, kelola tenant |
| `melati@egzy.test` | Admin Hotel Melati Indah (WP001) | Operasional hotelnya sendiri + status pajaknya |
| `serena@egzy.test` | Admin Hotel Serena (WP002) | idem |
| `villa@egzy.test` | Admin Villa Asri (WP007) | idem |
| `cempaka@egzy.test` | Admin Hotel Cempaka (WP008) | idem |
| `bahari@egzy.test` | Admin Resto Bahari (WP009) | idem (POS restoran) |
| `nirwana@egzy.test` | Admin Hotel Nirwana (WP010) | idem |
| `tamu1.wp001@egzy.test` dst. | Tamu website hotel | Riwayat booking di website hotel tsb. |

WP003–WP006 tidak memakai aplikasi (hanya tapping box), jadi tidak punya akun; datanya dilihat dari akun Bapenda.

## Cara membaca rekonsiliasi

Bapenda membandingkan **omzet tercatat** (dari aplikasi EGZY atau tapping box) dengan **settlement QRIS** dari 3 bank (BNI, BRI, bank bjb).

- Normalnya QRIS **lebih kecil** dari omzet, karena sebagian tamu membayar tunai (rasio ±0,5–0,6).
- Bila QRIS **lebih besar** dari omzet, pasti ada penjualan yang tidak tercatat → **temuan**. Potensi pajak dihitung dari estimasi omzet sebenarnya.
- Temuan **tidak otomatis ditagih**: perlu pemeriksaan dan penetapan (SKPDKB). Tagihan VA hanya untuk pajak yang dilaporkan.

## 10 wajib pajak dan ceritanya

| WP | Nama | Sumber omzet | Cerita | Agustus | September |
| --- | --- | --- | --- | --- | --- |
| WP001 | Hotel Melati Indah | Aplikasi EGZY (+ restoran) | **Patuh.** Semua transaksi tercatat. | Lunas | Lunas |
| WP002 | Hotel Serena | Aplikasi EGZY | **Kurang lapor.** Sebagian tamu tidak diinput; QRIS 1,14× lalu 1,32× omzet. | Temuan · Lunas | Temuan risiko tinggi · Menunggu bayar |
| WP003 | Guest House Kenanga | Tapping box | **Multi-outlet.** 2 MID QRIS; outlet kedua tidak terpasang tapping box. | Temuan · Lunas | Temuan · Menunggu bayar |
| WP004 | Resto Sunda Asli | Tapping box | **Patuh**, selalu bayar tepat waktu. | Lunas | Lunas |
| WP005 | Cafe Kopi Senja | Tapping box | **Selisih tipis** di September (risiko sedang). QRIS outlet baru `QR-SNJ-02` muncul di antrean MID tak terpetakan. | Lunas | Temuan · Belum terbit |
| WP006 | Warung Steak Jaya | Tapping box | **Refund QRIS** (nilai negatif) ikut dihitung; tetap patuh. | Lunas | Belum terbit |
| WP007 | Villa Asri | Aplikasi EGZY | **Tenant baru** (bergabung Oktober): kamar siap, baru 2 booking ke depan. | — | — |
| WP008 | Hotel Cempaka | Aplikasi EGZY | **Telat bayar:** VA Agustus kedaluwarsa tanpa pembayaran. | Terlambat · VA kedaluwarsa | Menunggu bayar |
| WP009 | Resto Bahari | Aplikasi EGZY (POS) | **Restoran** dengan order kasir; patuh. | Lunas | Menunggu bayar |
| WP010 | Hotel Nirwana | Aplikasi EGZY (+ restoran) | **Tamu bayar via VA BNI** milik hotel (± 40% booking). | Lunas | Lunas |

## Isi operasional tiap hotel

| | Melati | Serena | Villa Asri | Cempaka | Bahari | Nirwana |
| --- | --- | --- | --- | --- | --- | --- |
| Kamar | 13 (3 tipe, 3 lantai) | 13 | 5 villa | 13 | — | 13 |
| Booking | ±180 | ±185 | 2 | ±105 | — | ±145 |
| Order restoran | ±275 | — | — | — | ±640 | ±180 |

Tipe kamar hotel: Superior Twin (Rp 550.000), Deluxe King (Rp 780.000), Family Suite (Rp 1.250.000).
Status booking mengikuti tanggal hari ini: sudah lewat = **check-out** (±6% **batal**), sedang menginap = **check-in**, akan datang = **pending**.
Booking dan order yang batal **tidak** dihitung sebagai omzet.

## Tur 10 menit

1. **Bapenda** (`bapenda@egzy.test`) → menu *Pengawasan PBJT*, pilih **Masa 2026-09**:
   - KPI di atas: 3 WP terindikasi, 1 MID tak terpetakan.
   - *Daftar Temuan*: Hotel Serena di urutan teratas (risiko tinggi).
   - *Antrean MID*: `QR-SNJ-02` (calon outlet Kopi Senja yang belum terdaftar).
   - *Tagihan PBJT*: campuran Lunas / Menunggu bayar / Belum terbit. Tombol "Terbitkan VA" butuh kredensial BNI (lihat `deploy/README.md`).
2. Ganti ke **Masa 2026-08** → baris Hotel Cempaka berstatus **VA kedaluwarsa** dan **terlambat**.
3. **Hotel Serena** (`serena@egzy.test`) → *Pengawasan PBJT*: WP hanya melihat datanya sendiri ("Perlu klarifikasi") dan nomor VA tagihannya.
4. **Hotel Melati** (`melati@egzy.test`) → *Room Reservation › Room Status* (kamar terisi hari ini), *Booking List*, *Customer List*, *Order Manage › Order List*.
5. **Hotel Nirwana** (`nirwana@egzy.test`) → *Booking List*: booking ber-metode "Virtual Account BNI".

## Catatan

- Nomor VA di data demo **fiktif** (prefix 98829 untuk Bapenda, 88110 untuk hotel). Tombol "Cek status" ke bank akan menolak sampai kredensial BNI dipasang.
- Halaman depan website publik (slider/banner) masih konten bawaan vendor; daftar kamar muncul saat tamu mencari tanggal.
- Seed **mengganti** data operasional DB oversight, kontrol, dan tenant terdaftar. Jangan jalankan di server produksi berisi data nyata.
