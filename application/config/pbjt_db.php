<?php
/**
 * Kredensial & nama database TERPUSAT — dipakai aplikasi web DAN skrip CLI.
 *
 * Urutan prioritas:
 *   1. Variabel lingkungan: PBJT_DB_HOST, PBJT_DB_USER, PBJT_DB_PASS,
 *      PBJT_OVERSIGHT_DB, PBJT_CONTROL_DB, PBJT_BACKUP_DIR, EGZY_ENCRYPTION_KEY
 *   2. File lokal application/config/pbjt_db.local.php (TIDAK di-commit), berisi:
 *        <?php return ['host'=>'127.0.0.1','user'=>'egzy_app','pass'=>'rahasia', ...];
 *   3. Default pengembangan lokal (di bawah).
 *
 * File ini hanya mengembalikan array; tidak menghasilkan output.
 */
$__pbjt_local_file = __DIR__ . '/pbjt_db.local.php';
$__pbjt_local = is_file($__pbjt_local_file) ? (array) (require $__pbjt_local_file) : [];

$__pbjt_pick = function ($env, $key, $default) use ($__pbjt_local) {
    $v = getenv($env);
    if ($v !== false) return $v;
    return array_key_exists($key, $__pbjt_local) ? $__pbjt_local[$key] : $default;
};

return [
    'host'         => $__pbjt_pick('PBJT_DB_HOST', 'host', 'localhost'),
    'user'         => $__pbjt_pick('PBJT_DB_USER', 'user', 'user'),
    'pass'         => $__pbjt_pick('PBJT_DB_PASS', 'pass', ''),
    'oversight_db' => $__pbjt_pick('PBJT_OVERSIGHT_DB', 'oversight_db', 'hotelv3'),
    'control_db'   => $__pbjt_pick('PBJT_CONTROL_DB', 'control_db', 'pbjt_control'),
    'backup_dir'   => $__pbjt_pick('PBJT_BACKUP_DIR', 'backup_dir', '/Users/user/Downloads/pbjt_backups'),
    // 64 hex (32 byte). Kosong = kunci pengembangan (lihat config.php). Dibuat oleh install_vm.sh.
    'encryption_key' => $__pbjt_pick('EGZY_ENCRYPTION_KEY', 'encryption_key', ''),
];
