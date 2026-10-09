<?php
defined('BASEPATH') OR exit('No direct script access allowed');

    function dd($data)
    {
        echo "<pre>";
        print_r($data);
        echo "</pre>";
        die();
    }
    // Filter input data
     function filterInput($data = null)
    { 
        //if not empty posted data
        if (!empty($data)) { 
            $data = trim($data);
            $data = filter_var($data, FILTER_SANITIZE_STRING);
            $data = stripslashes($data);
            $data = htmlspecialchars($data);
            return $data;
        }
        return false;
    }

    // Check Project https
    if ( ! function_exists('is_https'))
    {
        function is_https()
        {
            if ( ! empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            {
                return TRUE;
            }
            elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            {
                return TRUE;
            }
            elseif ( ! empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off')
            {
                return TRUE;
            }

            return FALSE;
        }
    }

    // Get Project base_url
    if ( ! function_exists('base_url'))
    {
        function base_url(){

            $base_url = (is_https() ? "https://" : "http://").$_SERVER["HTTP_HOST"];
            $base_url.= str_replace(basename($_SERVER["SCRIPT_NAME"]), "", $_SERVER["SCRIPT_NAME"]);
            return $base_url;

        }
    }


    // Redirect page
    if ( ! function_exists('redirect'))
    {
        function redirect($url)
        {
            header('location:'.base_url().$url);
        }
    }

    // CSRF Token Generate
    if ( ! function_exists('gen_csrf_token'))
    {
        function gen_csrf_token()
        {
            if (function_exists('random_bytes')) {
                $token= bin2hex(random_bytes(32));
            } else {
                $token = bin2hex(openssl_random_pseudo_bytes(32));
            }
            return $token;
        }
    }
    function getCurrency(){
        $ci =& get_instance();
        $ci->load->database();
        $ci->db->select("curr_icon,position");
        $ci->db->from("currency");
        $ci->db->join("setting","setting.currency=currency.currencyid","left");
        $currency = $ci->db->get();
        $row = $currency->row();
        return $row;
    }
    function financial_year(){
        $ci =& get_instance();
        $ci->load->database();
        $ci->db->select("COUNT(fiyear_id) as num");
        $ci->db->from("tbl_financialyear");
        $ci->db->where("start_date<=",date("Y-m-d"));
        $ci->db->where("end_date>=",date("Y-m-d"));
        $ci->db->where("is_active",2);
        $query = $ci->db->get();
        $row = $query->row();
        return $row->num;
    }  
    function maxfindate(){
        $ci =& get_instance();
        $ci->load->database();
        $ci->db->select("end_date");
        $ci->db->from("tbl_financialyear");
        $ci->db->where("start_date<=",date("Y-m-d"));
        $ci->db->where("end_date>=",date("Y-m-d"));
        $ci->db->where("is_active",2);
        $query = $ci->db->get();
        $row = $query->row();
        return !empty($row->end_date)?$row->end_date:'';
    }  


    /**
     * ID posisi bawaan (mis. 6 = House Keeper, 9 = Waiter, 17 = Driver) PLUS posisi lain yang
     * namanya sama bila spasi/huruf besar diabaikan. Modul lama meng-hardcode pos_id, sehingga
     * pegawai pada posisi buatan pengguna ("Housekeeper") tak pernah muncul di daftar.
     */
    if (!function_exists('position_ids')) {
        function position_ids($pos_id){
            static $cache = array();
            $ci =& get_instance();
            $key = $ci->db->database . ':' . (int) $pos_id;
            if (isset($cache[$key])) return $cache[$key];
            $ids = array((int) $pos_id);
            // query() mentah: helper ini dipanggil DI TENGAH rantai query builder pemanggil.
            $rows = $ci->db->query("SELECT p.pos_id FROM position p JOIN position b ON b.pos_id = ?
                WHERE LOWER(REPLACE(p.position_name, ' ', '')) = LOWER(REPLACE(b.position_name, ' ', ''))", array((int) $pos_id))->result();
            foreach ($rows as $r) $ids[] = (int) $r->pos_id;
            return $cache[$key] = array_values(array_unique($ids));
        }
    }


    /** TRUE bila request ini berjalan di DB oversight (instance Bapenda), bukan DB tenant WP. */
    if (!function_exists('egzy_is_oversight')) {
        function egzy_is_oversight(){
            static $cfg = null;
            if ($cfg === null) $cfg = require APPPATH . 'config/pbjt_db.php';
            $ci =& get_instance();
            return isset($ci->db) && is_object($ci->db) && $ci->db->database === $cfg['oversight_db'];
        }
    }

?>