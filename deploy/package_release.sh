#!/usr/bin/env bash
#
# EGZY — bungkus rilis untuk deploy ke VM.
# Jalankan di mesin SUMBER (mis. Mac pengembang) dari mana saja:
#
#   ./deploy/package_release.sh [folder_output]
#
# Hasil: <folder_output>/egzy-release-YYYYmmdd_HHMMSS.tar.gz berisi
#   - kode aplikasi (tanpa cache, log, kredensial lokal termasuk kunci BNI, test, dump lama)
#   - _release_db/*.sql : dump SEMUA database (tenant, oversight, kontrol, template)
#
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
PHP_BIN="${PHP_BIN:-$(command -v php)}"
OUT="${1:-$ROOT/dist}"
STAMP="$(date +%Y%m%d_%H%M%S)"
WORK="$(mktemp -d)"
STAGE="$WORK/egzy-release"
trap 'rm -rf "$WORK"' EXIT

cfg() { "$PHP_BIN" -r '$c=require "application/config/pbjt_db.php"; echo $c["'"$1"'"];'; }

echo "==> Menyiapkan rilis dari $ROOT"
mkdir -p "$STAGE/_release_db" "$OUT"

echo "==> Dump database"
"$PHP_BIN" pbjt_backup.php backup --all "$STAGE/_release_db"
for db in "$(cfg oversight_db)" "$(cfg control_db)" tenant_template; do
  "$PHP_BIN" pbjt_backup.php backup "$db" "$STAGE/_release_db"
done

echo "==> Salin kode"
rsync -a \
  --exclude '.DS_Store' --exclude '.git' --exclude '/dist/' --exclude '/_release_db/' \
  --exclude 'application/cache/temp/*' --exclude 'application/logs/log-*' \
  --exclude 'application/config/pbjt_db.local.php' --exclude 'application/config/snap_bi.local.php' \
  --exclude '*.pem' --exclude '/tests/' \
  --exclude '/*.xlsx' --exclude '/*.xls' --exclude '/*.csv' --exclude '/*.docx' --exclude '/*.pdf' \
  --exclude '_snap_*' --exclude '_preview_*' --exclude '_frame*' \
  --exclude 'assets/data/backup/*.sql' --exclude 'assets/data/outgoing/*.sql' \
  --exclude 'error_log' \
  ./ "$STAGE/"

echo "==> Checksum dump"
( cd "$STAGE/_release_db" && shasum -a 256 *.sql > SHA256SUMS )

TAR="$OUT/egzy-release-$STAMP.tar.gz"
# Tanpa metadata macOS (xattr/AppleDouble) agar tar di Linux tidak memberi peringatan.
COPYFILE_DISABLE=1 tar --no-xattrs -C "$WORK" -czf "$TAR" egzy-release
echo
echo "Rilis siap: $TAR ($(du -h "$TAR" | cut -f1))"
echo "Database dibungkus: $(ls "$STAGE/_release_db"/*.sql | wc -l | tr -d ' ') file"
echo
echo "Langkah berikutnya:"
echo "  scp \"$TAR\" USER@IP_VM:/tmp/"
echo "  ssh USER@IP_VM 'cd /tmp && tar xzf $(basename "$TAR") && cd egzy-release && sudo ./deploy/install_vm.sh --domain DOMAIN --email EMAIL_ADMIN'"
