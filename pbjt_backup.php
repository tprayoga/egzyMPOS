<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } // skrip CLI: tolak akses via web
$__db = require __DIR__ . '/application/config/pbjt_db.php'; // kredensial terpusat
/**
 * CLI backup/restore per-tenant (silo).
 *
 *   php pbjt_backup.php backup <WP_ID|db_name|--all> [outdir]
 *   php pbjt_backup.php restore <db_name> <file.sql>
 *   php pbjt_backup.php list
 *
 * Default outdir: /Users/user/Downloads/pbjt_backups
 */
define('PBJT_CLI', true);
require __DIR__ . '/application/modules/pbjt/libraries/Pbjt_backup.php';

$DEFAULT_OUT = $__db['backup_dir'];
$cmd = $argv[1] ?? '';

$m = @new mysqli($__db['host'], $__db['user'], $__db['pass'], '');
if ($m->connect_errno) { fwrite(STDERR, "Koneksi gagal: " . $m->connect_error . "\n"); exit(1); }
$bk = new Pbjt_backup($m);

function human($b) { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024, 1) . ' KB'; }

try {
    if ($cmd === 'list') {
        echo "Tenant terdaftar:\n";
        foreach ($bk->tenants() as $wp => $db) echo "  $wp -> $db\n";

    } elseif ($cmd === 'backup') {
        $target = $argv[2] ?? '';
        $out = $argv[3] ?? $DEFAULT_OUT;
        if ($target === '') { fwrite(STDERR, "Sebutkan WP_ID / db_name / --all\n"); exit(1); }

        $tenants = $bk->tenants(); // wp_id => db
        $dbs = [];
        if ($target === '--all') {
            $dbs = array_values($tenants);
        } elseif (isset($tenants[strtoupper($target)])) {
            $dbs = [$tenants[strtoupper($target)]];
        } else {
            $dbs = [$target]; // anggap db_name langsung
        }
        foreach ($dbs as $db) {
            $r = $bk->dump($db, $out);
            printf("  backup %-16s -> %s (%s, %d tabel, %d baris)\n", $db, basename($r['path']), human($r['bytes']), $r['tables'], $r['rows']);
        }
        echo "Folder: $out\n";

    } elseif ($cmd === 'restore') {
        $db = $argv[2] ?? ''; $file = $argv[3] ?? '';
        if ($db === '' || $file === '') { fwrite(STDERR, "Usage: restore <db_name> <file.sql>\n"); exit(1); }
        $bk->restore($db, $file);
        echo "Restore selesai: $db <- " . basename($file) . "\n";

    } elseif ($cmd === 'prune') {
        // Retensi: hapus file backup lebih tua dari N hari.
        $days = (int) ($argv[2] ?? 14);
        $dir  = $argv[3] ?? $DEFAULT_OUT;
        $cut  = time() - $days * 86400;
        $n = 0; $freed = 0;
        foreach (glob(rtrim($dir, '/') . '/*.sql') as $f) {
            if (filemtime($f) < $cut) { $freed += filesize($f); @unlink($f); $n++; }
        }
        echo "Prune: hapus $n file lebih tua dari $days hari (" . human($freed) . ")\n";

    } else {
        fwrite(STDERR, "Perintah tidak dikenal. backup | restore | list | prune\n");
        exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "GAGAL: " . $e->getMessage() . "\n");
    exit(1);
}
$m->close();
