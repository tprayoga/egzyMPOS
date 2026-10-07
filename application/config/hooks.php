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
$hook['post_controller'][] = array(
    'class'    => 'Pbjt_sync_hook',
    'function' => 'sync',
    'filename' => 'Pbjt_sync_hook.php',
    'filepath' => 'hooks',
);
