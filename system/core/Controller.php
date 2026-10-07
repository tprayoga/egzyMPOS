<?php

/**
 * CodeIgniter
 *
 * An open source application development framework for PHP
 *
 * This content is released under the MIT License (MIT)
 *
 * Copyright (c) 2014 - 2017, British Columbia Institute of Technology
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package	CodeIgniter
 * @author	EllisLab Dev Team
 * @copyright	Copyright (c) 2008 - 2014, EllisLab, Inc. (https://ellislab.com/)
 * @copyright	Copyright (c) 2014 - 2017, British Columbia Institute of Technology (http://bcit.ca/)
 * @license	http://opensource.org/licenses/MIT	MIT License
 * @link	https://codeigniter.com
 * @since	Version 1.0.0
 * @filesource
 */
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Application Controller Class
 *
 * This class object is the super class that every library in
 * CodeIgniter will be assigned to.
 *
 * @package		CodeIgniter
 * @subpackage	Libraries
 * @category	Libraries
 * @author		EllisLab Dev Team
 * @link		https://codeigniter.com/user_guide/general/controllers.html
 */

#[\AllowDynamicProperties]

class CI_Controller extends Lic
{

	/**
	 * Reference to the CI singleton
	 *
	 * @var	object
	 */
	private static $instance;

	/**
	 * Class constructor
	 *
	 * @return	void
	 */
	public $load;
	public $db;

	public function __construct()
	{
		self::$instance = &$this;

		// Assign all the class objects that were instantiated by the
		// bootstrap file (CodeIgniter.php) to local class variables
		// so that CI can run as one big super object.
		foreach (is_loaded() as $var => $class) {
			$this->$var = &load_class($class);
		}

		$this->load = &load_class('Loader', 'core');
		$this->load->initialize();
		$CI = &get_instance();
		$stinfo = $CI->db->select("*")->from('setting')->get()->row();
		date_default_timezone_set($stinfo->timezone);
		log_message('info', 'Controller Class Initialized');
		$CI->db->query('SET SESSION sql_mode = ""');

		$isDemoMode = $CI->config->item('demo_mode');

		$segment1 = @$this->uri->segment(1);
		$segment2 = @$this->uri->segment(2);
		$segment3 = @$this->uri->segment(3);
		$segment5 = @$this->uri->segment(5);

		if ($isDemoMode && ($segment3 == 'delete' or $segment3 == 'uninstall' or $segment3 == 'seatdelete' or $segment3 == 'hallroomdelete' or $segment3 == 'comdelete' or $segment3 == 'delete_vehicle_details' or $segment3 == 'delete_flight_details' or $segment3 == 'delete_shiftassign' or $segment3 == 'deletevarient' or $segment3 == 'delete_leave_type' or $segment3 == 'delete_employhistory' or $segment3 == 'delete_canInfo' or $segment3 == 'finyear_delete' or $segment3 == 'finyear_delete' or $segment2 == 'customer-delete' or $segment3 == 'guestdelete' or $segment2 == 'wakeupcall-delete' or $segment2 == 'division-delete' or $segment2 == 'room-facilities-delete' or $segment2 == 'room-facilities-details-delete' or $segment2 == 'room-size-delete' or $segment2 == 'room-size-delete' or $segment2 == 'unit-delete' or $segment2 == 'product-delete' or $segment2 == 'supplier-delete' or $segment2 == 'pool_package_delete' or $segment2 == 'pool_delete' or $segment2 == 'pool_type_delete' or $segment2 == 'bed-delete' or $segment2 == 'booking-type-delete' or $segment2 == 'booking-typedtls-delete' or $segment2 == 'floor-plan-delete' or $segment2 == 'room-delete' or $segment2 == 'images-delete' or $segment2 == 'delete') && $segment1 != 'login') {

			$CI->session->set_flashdata('error', 'Form submissions are disabled in demo mode.');
			$CI->session->set_flashdata('exception', 'Form submissions are disabled in demo mode.');

			$referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : base_url();
			redirect($referrer);
			exit;
		}

		if ($isDemoMode && ($_SERVER['REQUEST_METHOD'] === 'POST' && !$this->input->is_ajax_request() && $segment1 != 'login' && $segment2 != 'placeorder')) {

			$CI->session->set_flashdata('error', 'Form submissions are disabled in demo mode.');
			$CI->session->set_flashdata('exception', 'Form submissions are disabled in demo mode.');

			$referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : base_url();
			redirect($referrer);
			exit;
		}
	}

	// --------------------------------------------------------------------

	/**
	 * Get the CI singleton
	 *
	 * @static
	 * @return	object
	 */
	public static function &get_instance()
	{
		return self::$instance;
	}
}
