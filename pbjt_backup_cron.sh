#!/usr/bin/env bash
#
# Backup terjadwal semua DB (silo) + retensi + log. Aman dijalankan berulang.
# Path diambil otomatis; bisa ditimpa lewat variabel lingkungan:
#   PBJT_BACKUP_DIR   folder tujuan backup     (default: dari application/config/pbjt_db.php)
#   RETENTION_DAYS    lama simpan (hari)        (default: 14)
#   PHP_BIN           biner php                 (default: php di PATH)
#
# Contoh crontab (harian 02:00):
#   0 2 * * * /var/www/egzy/pbjt_backup_cron.sh
#
set -euo pipefail

PROJECT="$(cd "$(dirname "$0")" && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php)}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
OUTDIR="${PBJT_BACKUP_DIR:-$("$PHP_BIN" -r '$c=require "'"$PROJECT"'/application/config/pbjt_db.php"; echo $c["backup_dir"];')}"
LOG="$OUTDIR/backup.log"

mkdir -p "$OUTDIR"
cd "$PROJECT"

{
  echo "==== [$(date '+%Y-%m-%d %H:%M:%S')] mulai backup ===="
  "$PHP_BIN" pbjt_backup.php backup --all "$OUTDIR"              # semua DB tenant
  "$PHP_BIN" pbjt_backup.php backup "$("$PHP_BIN" -r '$c=require "application/config/pbjt_db.php"; echo $c["oversight_db"];')" "$OUTDIR"   # DB oversight
  "$PHP_BIN" pbjt_backup.php backup "$("$PHP_BIN" -r '$c=require "application/config/pbjt_db.php"; echo $c["control_db"];')" "$OUTDIR"     # DB kontrol (registry)
  "$PHP_BIN" pbjt_backup.php backup tenant_template "$OUTDIR"   # template provisioning
  echo "---- retensi: buang backup > ${RETENTION_DAYS} hari ----"
  "$PHP_BIN" pbjt_backup.php prune "$RETENTION_DAYS" "$OUTDIR"
  echo "==== [$(date '+%Y-%m-%d %H:%M:%S')] selesai ===="
  echo
} >> "$LOG" 2>&1

echo "Backup selesai. Log: $LOG"
