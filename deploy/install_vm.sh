#!/usr/bin/env bash
#
# EGZY — skrip instalasi VM (Ubuntu 22.04 / 24.04 LTS).
# Jalankan sebagai root dari folder rilis hasil deploy/package_release.sh:
#
#   sudo ./deploy/install_vm.sh --domain egzy.contoh.go.id --email admin@contoh.go.id
#
# Opsi:
#   --domain NAMA        (wajib) nama domain/host aplikasi
#   --email EMAIL        email untuk sertifikat HTTPS (Let's Encrypt). Tanpa ini HTTPS dilewati.
#   --data DIR           folder dump .sql (default: <rilis>/_release_db)
#   --app-dir DIR        lokasi aplikasi        (default: /var/www/egzy)
#   --backup-dir DIR     lokasi backup          (default: /var/backups/egzy)
#   --db-user NAMA       user DB aplikasi       (default: egzy_app)
#   --db-pass PASS       password user DB       (default: dipakai ulang bila sudah ada, atau dibuat acak)
#   --no-https           jangan pasang sertifikat HTTPS
#   --no-firewall        jangan ubah firewall (ufw)
#   --force-restore      TIMPA database yang sudah berisi dengan dump (berbahaya!)
#   --strict             hapus pemilih tenant demo (Tenant.php) & folder install/ (dipindah ke backup)
#   --skip-os-check      izinkan OS selain Ubuntu 22.04/24.04
#
# Aman dijalankan berulang: paket/konfigurasi diperbarui, database yang sudah berisi TIDAK ditimpa.
#
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive LC_ALL=C.UTF-8 LANG=C.UTF-8

# ------------------------------------------------------------------ util
C_B=$'\e[1m'; C_G=$'\e[32m'; C_Y=$'\e[33m'; C_R=$'\e[31m'; C_0=$'\e[0m'
step() { echo; echo "${C_B}==> $*${C_0}"; }
ok()   { echo "   ${C_G}✔${C_0} $*"; }
warn() { echo "   ${C_Y}!${C_0} $*"; }
die()  { echo "${C_R}GAGAL:${C_0} $*" >&2; exit 1; }
trap 'echo "${C_R}Error pada baris $LINENO: $BASH_COMMAND${C_0}" >&2' ERR

svc() { # svc <aksi> <layanan>  — systemd bila ada, selain itu 'service' (mis. container)
  if [ -d /run/systemd/system ]; then systemctl "$1" "$2"; else service "$2" "$1"; fi
}
apache_apply() { # terapkan konfigurasi Apache; tahan race stop/start pada lingkungan tanpa systemd
  if [ -d /run/systemd/system ]; then systemctl restart apache2; return; fi
  service apache2 stop >/dev/null 2>&1 || true
  local i; for i in 1 2 3 4 5; do
    sleep 2
    service apache2 start >/dev/null 2>&1 && return 0
  done
  service apache2 start   # percobaan terakhir: tampilkan pesan aslinya bila tetap gagal
}
sql() { mariadb -N -B -e "$1"; }
as_www() { runuser -u www-data -- "$@"; }

# ------------------------------------------------------------------ argumen
SRC="$(cd "$(dirname "$0")/.." && pwd)"
DOMAIN=""; EMAIL=""; DATA_DIR="$SRC/_release_db"
APP_DIR="/var/www/egzy"; BACKUP_DIR="/var/backups/egzy"
DB_USER="egzy_app"; DB_PASS=""
DO_HTTPS=1; DO_FW=1; FORCE_RESTORE=0; STRICT=0; SKIP_OS=0
PHP_BIN="/usr/bin/php7.4"

while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2;;
    --email) EMAIL="$2"; shift 2;;
    --data) DATA_DIR="$2"; shift 2;;
    --app-dir) APP_DIR="$2"; shift 2;;
    --backup-dir) BACKUP_DIR="$2"; shift 2;;
    --db-user) DB_USER="$2"; shift 2;;
    --db-pass) DB_PASS="$2"; shift 2;;
    --no-https) DO_HTTPS=0; shift;;
    --no-firewall) DO_FW=0; shift;;
    --force-restore) FORCE_RESTORE=1; shift;;
    --strict) STRICT=1; shift;;
    --skip-os-check) SKIP_OS=1; shift;;
    -h|--help) sed -n '2,27p' "$0"; exit 0;;
    *) die "opsi tidak dikenal: $1 (lihat --help)";;
  esac
done

# ------------------------------------------------------------------ 0. pemeriksaan awal
step "0/10 Pemeriksaan awal"
[ "$(id -u)" -eq 0 ] || die "jalankan sebagai root (sudo)."
[ -n "$DOMAIN" ] || die "--domain wajib diisi."
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || die "--domain tidak valid: $DOMAIN"
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "--db-user hanya boleh huruf/angka/_"
[ -f "$SRC/index.php" ] && [ -f "$SRC/application/config/pbjt_db.php" ] || die "jalankan dari folder rilis EGZY (tidak menemukan index.php / pbjt_db.php di $SRC)."
. /etc/os-release
if [ "$ID" = "ubuntu" ] && [[ "$VERSION_ID" =~ ^(22\.04|24\.04)$ ]]; then ok "OS: $PRETTY_NAME"
elif [ "$SKIP_OS" -eq 1 ]; then warn "OS $PRETTY_NAME tidak diuji — lanjut karena --skip-os-check"
else die "OS $PRETTY_NAME tidak didukung (Ubuntu 22.04/24.04). Pakai --skip-os-check untuk memaksa."
fi
if [ -d "$DATA_DIR" ] && ls "$DATA_DIR"/*.sql >/dev/null 2>&1; then
  ok "Dump database: $(ls "$DATA_DIR"/*.sql | wc -l) file di $DATA_DIR"
  if [ -f "$DATA_DIR/SHA256SUMS" ]; then (cd "$DATA_DIR" && sha256sum -c --quiet SHA256SUMS) && ok "Checksum dump cocok"; fi
else
  warn "Tidak ada dump di $DATA_DIR — database tidak akan dipulihkan (pastikan sudah ada di server)."
fi

# ------------------------------------------------------------------ 1. paket
step "1/10 Instal paket (Apache, PHP 7.4, MariaDB)"
# VM dengan IPv6 yang tampak aktif tetapi tidak bisa keluar membuat apt/launchpad timeout -> paksa IPv4.
if ! curl -6 -fsS --max-time 8 -o /dev/null https://ppa.launchpadcontent.net/ 2>/dev/null \
   && curl -4 -fsS --max-time 8 -o /dev/null https://ppa.launchpadcontent.net/ 2>/dev/null; then
  echo 'Acquire::ForceIPv4 "true";' > /etc/apt/apt.conf.d/99egzy-force-ipv4
  ok "IPv6 tidak bisa dipakai keluar — apt dipaksa memakai IPv4"
fi
apt-get update -qq
apt-get install -y -qq software-properties-common ca-certificates curl rsync unzip cron openssl gnupg >/dev/null
if ! grep -rqs "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/; then
  # add-apt-repository memanggil API launchpad.net yang di sebagian jaringan timeout;
  # cadangannya: kunci PPA dari keyserver Ubuntu (sidik jari diverifikasi) + entri sources manual.
  if ! timeout 120 add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1; then
    warn "add-apt-repository gagal/timeout — memasang PPA ondrej/php secara manual"
    ONDREJ_FPR="14AA40EC0831756756D7F66C4F4EA0AAE5267A6C"
    KEYRING=/usr/share/keyrings/ondrej-php.gpg
    curl -4 -fsS --max-time 60 "https://keyserver.ubuntu.com/pks/lookup?op=get&options=mr&search=0x$ONDREJ_FPR" \
      | gpg --dearmor > "$KEYRING.tmp" || die "Gagal mengambil kunci PPA dari keyserver.ubuntu.com — cek akses internet keluar VM."
    gpg --show-keys --with-colons "$KEYRING.tmp" 2>/dev/null | grep -q "^fpr:::::::::$ONDREJ_FPR:" \
      || { rm -f "$KEYRING.tmp"; die "Sidik jari kunci PPA ondrej/php tidak cocok — dibatalkan."; }
    mv "$KEYRING.tmp" "$KEYRING" && chmod 644 "$KEYRING"
    echo "deb [signed-by=$KEYRING] https://ppa.launchpadcontent.net/ondrej/php/ubuntu $(. /etc/os-release; echo "$VERSION_CODENAME") main" \
      > /etc/apt/sources.list.d/ondrej-php.list
    ok "PPA ondrej/php dipasang manual (kunci terverifikasi)"
  fi
  apt-get update -qq
fi
apt-get install -y -qq apache2 mariadb-server mariadb-client \
  php7.4 libapache2-mod-php7.4 php7.4-cli php7.4-mysql php7.4-gd php7.4-mbstring \
  php7.4-curl php7.4-xml php7.4-zip php7.4-intl >/dev/null
update-alternatives --set php "$PHP_BIN" >/dev/null 2>&1 || true
for m in $(ls /etc/apache2/mods-enabled/ | grep -E '^php[0-9.]+\.load$' | sed 's/\.load$//' | grep -v '^php7\.4$' || true); do a2dismod -q "$m" || true; done
a2enmod -q rewrite headers ssl php7.4 >/dev/null
ok "PHP: $($PHP_BIN -r 'echo PHP_VERSION;') | Apache & MariaDB terpasang"
svc start mariadb || svc start mysql
svc start cron || true
ok "Layanan MariaDB & cron berjalan"

# ------------------------------------------------------------------ 2. user database
step "2/10 User database aplikasi"
LOCAL_CFG="$APP_DIR/application/config/pbjt_db.local.php"
if [ -z "$DB_PASS" ] && [ -f "$LOCAL_CFG" ]; then
  DB_PASS="$($PHP_BIN -r '$c=require "'"$LOCAL_CFG"'"; echo $c["pass"] ?? "";')"
  [ -n "$DB_PASS" ] && ok "Memakai ulang password dari konfigurasi yang ada"
fi
if [ -z "$DB_PASS" ]; then
  DB_PASS="$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 32)"
  ok "Password DB baru dibuat (acak, 32 karakter)"
fi
[[ "$DB_PASS" =~ ^[A-Za-z0-9_@%+=.,:-]+$ ]] || die "--db-pass mengandung karakter yang tidak didukung (pakai huruf/angka/_@%+=.,:-)"
sql "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
sql "ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
sql "GRANT ALL PRIVILEGES ON \`hotelv3\`.* TO '$DB_USER'@'localhost';"
sql "GRANT ALL PRIVILEGES ON \`pbjt\\_control\`.* TO '$DB_USER'@'localhost';"
sql "GRANT ALL PRIVILEGES ON \`tenant\\_%\`.* TO '$DB_USER'@'localhost';"
sql "FLUSH PRIVILEGES;"
ok "User '$DB_USER'@'localhost' siap (hak akses hanya ke DB EGZY)"

# ------------------------------------------------------------------ 3. kode
step "3/10 Salin kode ke $APP_DIR"
mkdir -p "$APP_DIR" "$BACKUP_DIR"
if [ "$SRC" != "$APP_DIR" ]; then
  rsync -a \
    --exclude '/_release_db/' --exclude '/dist/' --exclude '.DS_Store' --exclude '.git' \
    --exclude 'application/cache/temp/*' --exclude 'application/logs/log-*' \
    --exclude 'application/config/pbjt_db.local.php' \
    "$SRC/" "$APP_DIR/"
  ok "Kode disalin (file upload & konfigurasi lokal di server dipertahankan)"
else
  ok "Sumber = tujuan, penyalinan dilewati"
fi
mkdir -p "$APP_DIR/application/cache/temp" "$APP_DIR/application/logs" "$APP_DIR/assets/img/captcha" "$APP_DIR/assets/data"

# ------------------------------------------------------------------ 4. konfigurasi
step "4/10 Konfigurasi kredensial"
ENC_KEY=""
[ -f "$LOCAL_CFG" ] && ENC_KEY="$($PHP_BIN -r '$c=require "'"$LOCAL_CFG"'"; echo $c["encryption_key"] ?? "";')"
[[ "$ENC_KEY" =~ ^[a-f0-9]{64}$ ]] || ENC_KEY="$(openssl rand -hex 32)"
cat > "$LOCAL_CFG" <<PHP
<?php
// Dibuat oleh deploy/install_vm.sh pada $(date '+%Y-%m-%d %H:%M:%S'). Jangan dibagikan.
return [
    'host'       => 'localhost',
    'user'       => '$DB_USER',
    'pass'       => '$DB_PASS',
    'backup_dir' => '$BACKUP_DIR',
    'encryption_key' => '$ENC_KEY',
];
PHP
ok "Ditulis: $LOCAL_CFG"

# ------------------------------------------------------------------ 5. izin berkas
step "5/10 Kepemilikan & izin berkas"
chown -R root:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} +
find "$APP_DIR" -type f -exec chmod 644 {} +
chmod 640 "$LOCAL_CFG"
chmod 750 "$APP_DIR/pbjt_backup_cron.sh" "$APP_DIR/deploy/"*.sh
chown -R www-data:www-data "$APP_DIR/application/cache" "$APP_DIR/application/logs" \
  "$APP_DIR/assets/img" "$APP_DIR/assets/data" "$BACKUP_DIR"
# Folder upload gambar modul (menu restoran + thumbnail, kamar, fasilitas, hall, pegawai) dan uploads/.
# Dulu milik root -> upload gagal diam-diam dan gambar tak pernah tampil (Skenario Uji TC-013, TC-025).
mkdir -p "$APP_DIR/application/modules/ordermanage/assets/images/"{big,medium,small} "$APP_DIR/uploads"
for m in room_setting room_facilities hall_room hrm; do mkdir -p "$APP_DIR/application/modules/$m/assets/images"; done
UPLOAD_DIRS="$(ls -d "$APP_DIR"/application/modules/*/assets/images) $APP_DIR/uploads"
chown -R www-data:www-data $UPLOAD_DIRS
chmod 750 "$BACKUP_DIR"
ok "Hanya cache, log, upload gambar, data aplikasi & folder backup yang bisa ditulis web server"

# ------------------------------------------------------------------ 6. database
step "6/10 Pulihkan database"
restored=0; skipped=0
if ls "$DATA_DIR"/*.sql >/dev/null 2>&1; then
  for f in "$DATA_DIR"/*.sql; do
    db="$(basename "$f" | sed -E 's/_[0-9]{8}_[0-9]{6}\.sql$//')"
    [[ "$db" =~ ^(hotelv3|pbjt_control|tenant_[a-z0-9_]+)$ ]] || { warn "lewati $f (nama DB tak dikenali)"; continue; }
    n="$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db';")"
    if [ "$n" -gt 0 ] && [ "$FORCE_RESTORE" -eq 0 ]; then
      warn "$db sudah berisi $n tabel — TIDAK ditimpa (pakai --force-restore bila memang ingin)"; skipped=$((skipped+1)); continue
    fi
    if [ "$n" -gt 0 ]; then
      as_www "$PHP_BIN" "$APP_DIR/pbjt_backup.php" backup "$db" "$BACKUP_DIR" >/dev/null && warn "$db dibackup dulu sebelum ditimpa"
    fi
    as_www "$PHP_BIN" "$APP_DIR/pbjt_backup.php" restore "$db" "$f" >/dev/null
    ok "Dipulihkan: $db"; restored=$((restored+1))
  done
fi
for need in hotelv3 pbjt_control tenant_template; do
  n="$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$need';")"
  [ "$n" -gt 0 ] || die "database wajib '$need' belum ada. Sertakan dump-nya (deploy/package_release.sh) lalu ulangi."
done
ok "Pulih: $restored | dilewati (sudah ada): $skipped"
( cd "$APP_DIR" && as_www "$PHP_BIN" pbjt_migrate_tenants.php | tail -1 | sed 's/^/   /' )
( cd "$APP_DIR" && as_www "$PHP_BIN" snap_setup.php migrate | sed 's/^/   /' )

# ------------------------------------------------------------------ 7. Apache
step "7/10 Apache virtual host"
cat > /etc/apache2/sites-available/egzy.conf <<APACHE
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $APP_DIR
    SetEnv CI_ENV production

    <Directory $APP_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^(.*)\$ index.php?/\$1 [L]
    </Directory>

    # Folder sensitif (termasuk dump DB di assets/data) ...
    <DirectoryMatch "^$APP_DIR/(install|system|vendor|application|assets/data|deploy)/">
        Require all denied
    </DirectoryMatch>
    # ... kecuali CSS/JS modul yang dimuat dari application/
    <DirectoryMatch "^$APP_DIR/application/modules/[^/]+/assets/">
        Require all granted
    </DirectoryMatch>
    # Folder upload yang bisa ditulis web server: sajikan sebagai berkas, jangan pernah dieksekusi.
    <DirectoryMatch "^$APP_DIR/(application/modules/[^/]+/assets/images|assets/img|uploads)/">
        php_admin_flag engine off
        <FilesMatch "\.(php[0-9]?|phtml|phar)\$">
            Require all denied
        </FilesMatch>
    </DirectoryMatch>
    # Skrip CLI, patcher, dan file tersembunyi
    <FilesMatch "^((pbjt|snap)_.*\.(php|sh)|seed_demo\.php|server_router\.php|update_module\.php|composer\.(json|lock)|error_log|\..*)\$">
        Require all denied
    </FilesMatch>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    ErrorLog \${APACHE_LOG_DIR}/egzy_error.log
    CustomLog \${APACHE_LOG_DIR}/egzy_access.log combined
</VirtualHost>
APACHE
echo "ServerName $DOMAIN" > /etc/apache2/conf-available/egzy-servername.conf
a2enconf -q egzy-servername >/dev/null
a2dissite -q 000-default >/dev/null 2>&1 || true
a2ensite -q egzy >/dev/null
apache2ctl configtest 2>&1 | grep -q "Syntax OK" || { apache2ctl configtest; die "konfigurasi Apache tidak valid."; }
apache_apply
ok "Virtual host $DOMAIN aktif"

# ------------------------------------------------------------------ 8. HTTPS
step "8/10 HTTPS"
SCHEME="http"
if [ "$DO_HTTPS" -eq 1 ] && [ -n "$EMAIL" ]; then
  apt-get install -y -qq certbot python3-certbot-apache >/dev/null
  # Vhost HTTPS dibuat certbot SEKALI dari salinan egzy.conf; saat update, aturan baru tak ikut.
  # Sisihkan agar certbot membuatnya ulang dari egzy.conf terbaru (sertifikat lama dipakai ulang).
  LE_SSL=/etc/apache2/sites-available/egzy-le-ssl.conf
  if [ -f "$LE_SSL" ]; then
    a2dissite -q egzy-le-ssl >/dev/null 2>&1 || true
    mv "$LE_SSL" "$LE_SSL.bak"
  fi
  if certbot --apache -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect --keep-until-expiring; then
    rm -f "$LE_SSL.bak"
    SCHEME="https"
    sed -i -E "s/(\\\$config\['cookie_secure'\][[:space:]]*=[[:space:]]*)FALSE/\1TRUE/; s/(\\\$config\['cookie_httponly'\][[:space:]]*=[[:space:]]*)FALSE/\1TRUE/" \
      "$APP_DIR/application/config/config.php"
    ok "Sertifikat terpasang; cookie sesi disetel Secure + HttpOnly"
  else
    if [ -f "$LE_SSL.bak" ]; then   # kembalikan vhost HTTPS lama agar situs tetap jalan
      mv "$LE_SSL.bak" "$LE_SSL"; a2ensite -q egzy-le-ssl >/dev/null; apache_apply
      warn "certbot gagal; vhost HTTPS lama dikembalikan (aturan Apache terbaru belum berlaku di HTTPS)."
    fi
    warn "certbot gagal (DNS $DOMAIN sudah mengarah ke VM ini? port 80 terbuka?). Aplikasi tetap jalan via HTTP; ulangi skrip setelah DNS siap."
  fi
else
  warn "HTTPS dilewati (tanpa --email atau --no-https). WAJIB diaktifkan sebelum go-live."
fi

# ------------------------------------------------------------------ 9. cron & firewall & strict
step "9/10 Backup terjadwal, firewall, pengerasan"
CRONLINE="0 2 * * * PHP_BIN=$PHP_BIN $APP_DIR/pbjt_backup_cron.sh"
( crontab -u www-data -l 2>/dev/null | grep -v 'pbjt_backup_cron.sh' || true; echo "$CRONLINE" ) | crontab -u www-data -
ok "Cron www-data: backup harian 02:00 → $BACKUP_DIR (retensi 14 hari)"
if [ "$DO_FW" -eq 1 ]; then
  if command -v ufw >/dev/null || apt-get install -y -qq ufw >/dev/null; then
    # Izinkan SSH DULU (profil OpenSSH, atau port 22 bila profil tak ada) — firewall hanya diaktifkan
    # bila izin SSH berhasil dibuat, agar sesi SSH tidak pernah terkunci.
    { { ufw allow OpenSSH >/dev/null 2>&1 || ufw allow 22/tcp >/dev/null; } && ufw allow 80/tcp >/dev/null && ufw allow 443/tcp >/dev/null \
        && ufw --force enable >/dev/null && ok "Firewall aktif: SSH, 80, 443"; } \
      || warn "ufw tidak bisa diaktifkan di lingkungan ini (mis. container) — atur firewall di level cloud."
  fi
else
  warn "Firewall dilewati (--no-firewall)"
fi
if [ "$STRICT" -eq 1 ]; then
  RM="$BACKUP_DIR/dihapus-dari-web_$(date +%Y%m%d_%H%M%S)"; mkdir -p "$RM"
  [ -f "$APP_DIR/application/controllers/Tenant.php" ] && mv "$APP_DIR/application/controllers/Tenant.php" "$RM/" && ok "Tenant.php (pemilih demo) dipindah ke $RM"
  [ -d "$APP_DIR/install" ] && mv "$APP_DIR/install" "$RM/" && ok "Folder install/ dipindah ke $RM"
fi

# ------------------------------------------------------------------ 10. pengecekan
step "10/10 Pengecekan"
fail=0
chk() { # chk <path> <kode_harapan...>
  local p="$1"; shift
  local code; code="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $DOMAIN" "http://127.0.0.1/$p")"
  for e in "$@"; do [ "$code" = "$e" ] && { ok "$(printf '%-48s %s' "/$p" "$code")"; return; }; done
  # HTTPS aktif: HTTP akan diarahkan (301) — ikuti lewat https
  if [ "$code" = "301" ] && [ "$SCHEME" = "https" ]; then
    code="$(curl -sk -o /dev/null -w '%{http_code}' --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/$p")"
    for e in "$@"; do [ "$code" = "$e" ] && { ok "$(printf '%-48s %s' "/$p" "$code")"; return; }; done
  fi
  echo "   ${C_R}✘${C_0} $(printf '%-48s %s (harap: %s)' "/$p" "$code" "$*")"; fail=$((fail+1))
}
chk login 200
chk assets/css/pro-theme.css 200
chk application/modules/pbjt/assets/css/style.css 200
chk pbjt_silo_setup.php 403 404
chk update_module.php 403 404
chk assets/data/outgoing/backup.sql 403 404
chk application/config/pbjt_db.local.php 403 404
chk install/index.php 403 404
chk dashboard/autoupdate/download_backup 404
chk module 404
chk application/config/snap_bi.local.php 403 404
chk snap_setup.php 403 404
chk seed_demo.php 403 404
for d in "$APP_DIR/application/modules/ordermanage/assets/images/small" "$APP_DIR/application/modules/room_setting/assets/images" \
         "$APP_DIR/application/modules/room_facilities/assets/images" "$APP_DIR/application/modules/hall_room/assets/images" \
         "$APP_DIR/application/modules/hrm/assets/images"; do
  if as_www test -w "$d"; then ok "$(printf '%-48s %s' "${d#$APP_DIR/}" 'bisa ditulis www-data')"; else echo "   ${C_R}✘${C_0} ${d#$APP_DIR/} tidak bisa ditulis www-data"; fail=$((fail+1)); fi
done
if ( cd "$APP_DIR" && as_www "$PHP_BIN" pbjt_test_isolation.php | tail -1 | grep -q "ISOLASI TENANT OK" ); then ok "Uji isolasi tenant: OK"; else echo "   ${C_R}✘${C_0} Uji isolasi tenant gagal"; fail=$((fail+1)); fi

CRED=/root/egzy-credentials.txt
umask 077
cat > "$CRED" <<TXT
EGZY — kredensial instalasi ($(date '+%Y-%m-%d %H:%M:%S'))
URL        : $SCHEME://$DOMAIN/login
DB user    : $DB_USER@localhost
DB password: $DB_PASS
Konfigurasi: $LOCAL_CFG
Backup     : $BACKUP_DIR (harian 02:00)
TXT

echo
if [ "$fail" -eq 0 ]; then echo "${C_G}${C_B}Instalasi selesai tanpa kegagalan.${C_0}"; else echo "${C_Y}${C_B}Instalasi selesai dengan $fail pengecekan gagal — lihat di atas.${C_0}"; fi
cat <<INFO

  Aplikasi : $SCHEME://$DOMAIN/login
  Kredensial DB disimpan di $CRED (hanya root).

  Sebelum go-live:
   1. Bila memuat data demo: rotasi password semua akun -> sudo -u www-data php $APP_DIR/pbjt_rotate_passwords.php
   2. Hapus tenant & data demo bila tidak dipakai.
   3. Salin $BACKUP_DIR ke penyimpanan di luar VM (off-site).
INFO
[ "$STRICT" -eq 1 ] || echo "   4. Jalankan ulang dengan --strict untuk menghapus Tenant.php & install/ dari web."
exit "$fail"
