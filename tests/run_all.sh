#!/usr/bin/env bash
# Jalankan seluruh test regresi EGZY. Urutan penting: booking_va_test menyiapkan DB uji
# `snap_test_hotel` yang dipakai test keamanan & autentikasi.
#   ./tests/run_all.sh [URL_APP_DEV]   (opsional, mis. http://127.0.0.1:8747 untuk smoke HTTP)
set -u
cd "$(dirname "$0")/.."
APP="${1:-}"
fail=0
run() {
  printf '%-42s ' "$1"
  out="$(php "$@" 2>&1)"; rc=$?
  echo "$out" | tail -1
  [ $rc -eq 0 ] || { fail=1; echo "$out" | grep -E 'GAGAL' | sed 's/^/    /'; }
}
run tests/snapbi/run_tests.php ${APP:+--app=$APP}
run tests/snapbi/pbjt_tagihan_test.php
run tests/snapbi/booking_va_test.php
run tests/security_regression_test.php
run tests/auth_regression_test.php
run tests/skenario_uji_test.php
run tests/pbjt_kamar_stpd_test.php
run tests/pbjt_resto_taxmgt_test.php
run pbjt_test_isolation.php
exit $fail
