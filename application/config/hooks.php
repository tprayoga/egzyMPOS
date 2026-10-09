<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| Hooks
| -------------------------------------------------------------------------
| This file lets you define "hooks" to extend CI without hacking the core
| files.  Please see the user guide for info:
|
|	https://codeigniter.com/user_guide/general/hooks.html
|
*/

/*
| PBJT — sinkronisasi omzet saat transaksi (booking/order) tersimpan.
| Dipicu setelah controller berjalan; hanya bertindak pada aksi penyimpanan transaksi.
*/
// PBJT per transaksi (engine Pbjt_pajak) — SEBELUM Pbjt_sync_hook agar pengawasan membaca angka final.
$hook['post_controller'][] = array(
    'class'    => 'Egzy_pbjt_hook',
    'function' => 'sync',
    'filename' => 'Egzy_pbjt_hook.php',
    'filepath' => 'hooks',
);
$hook['post_controller'][] = array(
    'class'    => 'Pbjt_sync_hook',
    'function' => 'sync',
    'filename' => 'Pbjt_sync_hook.php',
    'filepath' => 'hooks',
);

// Instance Bapenda (DB oversight): tolak modul operasional hotel/resto di server.
$hook['post_controller_constructor'][] = array(
    'class'    => 'Egzy_oversight_guard',
    'function' => 'guard',
    'filename' => 'Egzy_oversight_guard.php',
    'filepath' => 'hooks',
);

// Zona waktu per tenant dari setting.timezone (sebelum method controller berjalan).
$hook['post_controller_constructor'][] = array(
    'class'    => 'Egzy_timezone',
    'function' => 'apply',
    'filename' => 'Egzy_timezone.php',
    'filepath' => 'hooks',
);
