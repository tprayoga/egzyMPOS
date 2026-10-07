# Deploy EGZY ke VM

Dua skrip:

| Skrip | Jalan di | Fungsi |
| --- | --- | --- |
| `deploy/package_release.sh` | mesin sumber (Mac pengembang) | Bungkus kode + dump **9 database** jadi `dist/egzy-release-*.tar.gz` |
| `deploy/install_vm.sh` | VM Ubuntu 22.04/24.04 (root) | Instal paket, DB user, kode, konfigurasi, Apache, HTTPS, cron, firewall, lalu cek |

## Alur

```bash
# 1. Di Mac (folder proyek)
./deploy/package_release.sh
scp dist/egzy-release-*.tar.gz USER@IP_VM:/tmp/

# 2. Di VM
cd /tmp && tar xzf egzy-release-*.tar.gz && cd egzy-release
sudo ./deploy/install_vm.sh --domain egzy.contoh.go.id --email admin@contoh.go.id
```

Pastikan DNS domain sudah mengarah ke IP VM dan port 80/443 terbuka **sebelum** menjalankan dengan `--email`, agar sertifikat HTTPS bisa diterbitkan. Tanpa `--email`, aplikasi tetap terpasang via HTTP; jalankan ulang skrip setelah DNS siap.

## Opsi `install_vm.sh`

| Opsi | Arti |
| --- | --- |
| `--domain NAMA` | **wajib** — domain aplikasi |
| `--email EMAIL` | email Let's Encrypt; tanpa ini HTTPS dilewati |
| `--data DIR` | folder dump `.sql` (default `_release_db/` di paket) |
| `--app-dir DIR` | lokasi aplikasi (default `/var/www/egzy`) |
| `--backup-dir DIR` | lokasi backup (default `/var/backups/egzy`) |
| `--db-user`, `--db-pass` | user/password DB aplikasi (default `egzy_app`, password acak atau dipakai ulang) |
| `--no-https`, `--no-firewall` | lewati HTTPS / ufw |
| `--strict` | pindahkan `Tenant.php` (pemilih demo) & folder `install/` keluar dari web |
| `--force-restore` | **timpa** DB yang sudah berisi (DB lama di-backup dulu) |

## Sifat penting

- **Aman diulang.** Paket & konfigurasi diperbarui; database yang sudah berisi **tidak ditimpa** kecuali `--force-restore`.
- **Password DB** disimpan di `application/config/pbjt_db.local.php` (640, root:www-data) dan ringkasannya di `/root/egzy-credentials.txt` (hanya root).
- **Akses web ditutup** untuk: `application/` (kecuali `application/modules/*/assets/`), `system/`, `vendor/`, `install/`, `assets/data/` (berisi dump DB), `deploy/`, skrip `pbjt_*`, `update_module.php`, `composer.*`, file tersembunyi.
- **Backup harian 02:00** (cron user `www-data`) untuk semua DB, retensi 14 hari.
- Pengecekan akhir memverifikasi halaman publik 200, file sensitif 403/404, dan uji isolasi tenant.

## Pembaruan aplikasi berikutnya

Buat paket baru, salin, lalu jalankan `install_vm.sh` lagi dengan opsi yang sama. Kode diperbarui, data di server tidak disentuh, dan migrasi skema (`application/migrations_pbjt/`) diterapkan otomatis ke semua tenant.

## Pembayaran VA BNI (SNAP BI)

Kredensial **tidak** ikut paket rilis (dikecualikan: `snap_bi.local.php`, `*.pem`). Pasang langsung di VM:

```bash
sudo mkdir -p /etc/egzy/snap && cd /etc/egzy/snap
sudo openssl genrsa -out bapenda_private.pem 2048            # satu per profil (Bapenda / tiap hotel)
sudo openssl rsa -in bapenda_private.pem -pubout -out bapenda_public.pem   # yang dikirim ke BNI/ASPI
# simpan public key BNI (dari BNI) sebagai /etc/egzy/snap/bni_public.pem
sudo chown root:www-data /etc/egzy/snap/* && sudo chmod 640 /etc/egzy/snap/*

cd /var/www/egzy/application/config
sudo cp snap_bi.local.php.example snap_bi.local.php && sudo nano snap_bi.local.php
sudo chown root:www-data snap_bi.local.php && sudo chmod 640 snap_bi.local.php

cd /var/www/egzy
sudo -u www-data php snap_setup.php check            # semua OK?
sudo -u www-data php snap_setup.php token bapenda    # uji token ke BNI
```

URL yang didaftarkan ke BNI/ASPI (wajib HTTPS):

| Layanan | URL |
| --- | --- |
| Token B2B | `https://DOMAIN/v1.0/access-token/b2b` |
| Inquiry VA | `https://DOMAIN/v1.0/transfer-va/inquiry` |
| Notifikasi bayar | `https://DOMAIN/v1.0/transfer-va/payment` |

Profil hotel = nama DB tenant (`tenant_wp001`, ...). Selama profil hotel belum ada, metode "Virtual Account BNI" tidak tampil di checkout hotel itu. Notifikasi yang gagal diteruskan dapat diulang: `sudo -u www-data php index.php snapbi/snap/redispatch`.

## Checklist go-live

- [ ] DNS domain → IP VM; jalankan dengan `--email` (HTTPS aktif) dan `--strict`.
- [ ] VM di pusat data Indonesia (PDN/cloud lokal) sesuai ketentuan data pemda.
- [ ] Data demo dihapus, atau semua passwordnya dirotasi: `sudo -u www-data php pbjt_rotate_passwords.php` (lihat `docs/DATA_DEMO.md`).
- [ ] `/root/egzy-credentials.txt` dipindahkan ke pengelola sandi, lalu dihapus dari VM.
- [ ] Backup `/var/backups/egzy` disalin ke luar VM (off-site), uji restore sekali.
- [ ] Kredensial BNI terpasang, `snap_setup.php check` OK, URL callback didaftarkan.
