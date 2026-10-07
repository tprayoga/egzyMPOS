<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Endpoint SNAP BI yang dipanggil bank (path standar ASPI).
$route['v1\.0/access-token/b2b']    = 'snapbi/snap/inbound';
$route['v1\.0/transfer-va/inquiry'] = 'snapbi/snap/inbound';
$route['v1\.0/transfer-va/payment'] = 'snapbi/snap/inbound';
