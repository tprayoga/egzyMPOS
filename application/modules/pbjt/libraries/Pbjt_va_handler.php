<?php
require_once __DIR__ . '/Pbjt_tagihan.php';

/**
 * Handler SNAP purpose 'pbjt': VA lunas -> tagihan PBJT lunas.
 * Didaftarkan di config/snap_bi.php ('handlers' => ['pbjt' => ...]).
 */
class Pbjt_va_handler
{
    private $db;

    public function __construct(mysqli $oversight = null)
    {
        if (!$oversight) {
            $c = require dirname(__DIR__, 3) . '/config/pbjt_db.php';
            $oversight = @new mysqli($c['host'], $c['user'], $c['pass'], $c['oversight_db']);
            if ($oversight->connect_errno) throw new RuntimeException('DB oversight tidak terjangkau: ' . $oversight->connect_error);
        }
        $this->db = $oversight;
    }

    public function onPaid(array $va)
    {
        (new Pbjt_tagihan($this->db))->tandaiLunas($va);
    }
}
