<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Terapkan zona waktu dari setting.timezone DB aktif (tenant), bila valid. Default: index.php (WIB). */
class Egzy_timezone
{
    public function apply()
    {
        $CI =& get_instance();
        if (!$CI || !isset($CI->db) || !is_object($CI->db)) return;
        $row = @$CI->db->query("SELECT timezone FROM setting LIMIT 1");
        $tz = ($row && ($r = $row->row())) ? trim((string) $r->timezone) : '';
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
        }
    }
}
