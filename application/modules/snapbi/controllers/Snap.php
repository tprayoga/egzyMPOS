<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'modules/snapbi/libraries/Snap_service.php';
require_once APPPATH . 'modules/snapbi/libraries/Snap_inbound.php';

/**
 * Endpoint SNAP BI yang dipanggil BNI. Tanpa sesi/CSRF; keamanan sepenuhnya
 * dari token B2B + signature (lihat Snap_inbound). Rute di config/routes.php modul.
 */
class Snap extends MX_Controller
{
    public function inbound()
    {
        try {
            $cfg = require APPPATH . 'config/snap_bi.php';
            $store = Snap_store::connect();
            $svc = new Snap_service($cfg, $store);
            $in = new Snap_inbound($cfg, $store, [$svc, 'dispatch']);

            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            list($code, $body) = $in->handle($_SERVER['REQUEST_METHOD'], $path, $this->headers(), file_get_contents('php://input'));
        } catch (Throwable $e) {
            log_message('error', 'SNAP inbound: ' . $e->getMessage());
            $code = 500;
            $body = ['responseCode' => '5000000', 'responseMessage' => 'General Error'];
        }
        $this->output
            ->set_status_header($code)
            ->set_content_type('application/json')
            ->set_output(json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    /** CLI: ulangi penerusan VA lunas yang tertunda.  php index.php snapbi/snap/redispatch */
    public function redispatch()
    {
        if (!is_cli()) show_404();
        $cfg = require APPPATH . 'config/snap_bi.php';
        $svc = new Snap_service($cfg, Snap_store::connect());
        echo 'Diteruskan: ' . $svc->redispatchPending() . "\n";
        foreach ($svc->store()->undispatched() as $va) {
            echo "  masih tertunda #{$va['id']} {$va['purpose']}/{$va['ref']}: {$va['dispatch_error']}\n";
        }
    }

    private function headers()
    {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) $h[str_replace('_', '-', substr($k, 5))] = $v;
        }
        if (isset($_SERVER['CONTENT_TYPE'])) $h['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        return $h;
    }
}
