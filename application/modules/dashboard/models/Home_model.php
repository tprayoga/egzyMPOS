<?php defined('BASEPATH') OR exit('No direct script access allowed');

class Home_model extends CI_Model {


	public function checkUser($data = array())
	{
		$uid = egzy_login_check($this->db, 'user', 'id', 'password', array('email' => $data['email']), $data['password']);
		return $this->db->select("
				user.id, 
				CONCAT_WS(' ', user.firstname, user.lastname) AS fullname,
				user.email, 
				user.image, 
				user.last_login,
				user.last_logout, 
				user.ip_address, 
				user.status, 
				user.is_admin, 
				IF (user.is_admin=1, 'Admin', 'User') as user_level
			")
			->from('user')
			->where('user.id', $uid)
			->get();
	}

	public function userPermission($id = null)
	{
		return $this->db->select("
			module.controller, 
			module_permission.fk_module_id, 
			module_permission.create, 
			module_permission.read, 
			module_permission.update, 
			module_permission.delete
			")
			->from('module_permission')
			->join('module', 'module.id = module_permission.fk_module_id', 'full')
			->where('module_permission.fk_user_id', $id)
			->get()
			->result();
	}


	public function last_login($id = null)
	{
		return $this->db->set('last_login', date('Y-m-d H:i:s'))
			->set('ip_address', $this->input->ip_address())
			->where('id',$this->session->userdata('id'))
			->update('user');
	}

	public function last_logout($id = null)
	{
		return $this->db->set('last_logout', date('Y-m-d H:i:s'))
			->where('id', $this->session->userdata('id'))
			->update('user');
	}

	public function profile($id = null)
	{
		return $this->db->select("
			*, 
				CONCAT_WS(' ', firstname, lastname) AS fullname,
				IF (user.is_admin=1, 'Admin', 'User') as user_level
			")
			->from("user")
			->where("id", $id)
			->get()
			->row();
	}

	public function setting($data = array())
	{
		return $this->db->where('id', $data['id'])
			->update('user', $data);
	}
	
	public function countorder()
	{
		$this->db->select('*');
        $this->db->from('booked_info');
		$this->db->where('bookingstatus=', 5);
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->num_rows();  
        }
        return 0;
	}
	public function countcheckin()
	{
		$this->db->select('*');
        $this->db->from('booked_info');
		$this->db->where('bookingstatus=', 4);
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->num_rows();  
        }
        return 0;
	}
	public function countpending()
	{
		$this->db->select('*');
        $this->db->from('booked_info');
		 $this->db->where('bookingstatus=', 0);
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->num_rows();  
        }
        return 0;
	}
	public function countcancel()
	{
		$this->db->select('*');
        $this->db->from('booked_info');
		 $this->db->where('bookingstatus=', 1);
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->num_rows();  
        }
        return 0;
	}
	public function countcompleteorder()
	{
		$this->db->select('*');
        $this->db->from('customer_order');
		 $this->db->where('order_status', 4);
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->num_rows();  
        }
        return 0;
	}
	
	public function todayorder()
	{
		$today=date('Y-m-d');
		$this->db->select('*');
        $this->db->from('booked_info');
		$this->db->where('date(date_time)', $today);
		$this->db->where('bookingstatus!=', 1);
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->num_rows();  
        }
        return 0;
	}
	
	public function totalcustomer()
	{
		$this->db->select('*');
        $this->db->from('customerinfo');
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->num_rows();  
        }
        return 0;
	}
	public function customerlist()
	{
		$this->db->select('*');
        $this->db->from('customerinfo');
		$this->db->order_by('customerid', 'DESC');
		$this->db->limit(50);
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->result();  
        }
        return false;
	}
	
	public function totalamount()
	{
		$today=date('Y-m-d');
		$this->db->select('SUM(total_price) as amount');
        $this->db->from('booked_info');
		$this->db->where('bookingstatus!=', 1);
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->row();  
        }
        return 0;
	}
	public function totalreservation()
	{
		$this->db->select('*');
        $this->db->from('tblreservation');
		$this->db->where('status', '5');
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->num_rows();  
        }
        return 0;
	}
	public function todayorderlist()
	{
		$today=date('Y-m-d');
		$this->db->select('booked_info.*,customerinfo.cust_phone,customerinfo.firstname,customerinfo.lastname');
        $this->db->from('booked_info');
		$this->db->join('customerinfo','booked_info.cutomerid=customerinfo.customerid','left');
		$this->db->where('booked_info.checkindate', $today);
		$this->db->where('booked_info.bookingstatus!=', 1);
		$this->db->order_by('booked_info.bookedid', 'DESC');
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->result();  
        }
        return false;
	}
	public function nextdayorderlist()
	{
	    $nextDate = date("Y-m-d", strtotime("+ 1 day"));;
		$this->db->select('booked_info.*,customerinfo.cust_phone,customerinfo.firstname,customerinfo.lastname');
        $this->db->from('booked_info');
		$this->db->join('customerinfo','booked_info.cutomerid=customerinfo.customerid','left');
		$this->db->where('booked_info.checkindate', $nextDate);
		$this->db->where('booked_info.bookingstatus!=', 1);
		$this->db->order_by('booked_info.bookedid', 'DESC');
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->result();  
        }
        return false;
	}
	public function latestoredercount()
	{
		$this->db->select('*');
        $this->db->from('booked_info');
		$this->db->where('isSeen', 0);
		$this->db->or_where('isSeen',NULL);
		$this->db->order_by('booking_number', 'DESC');
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->num_rows;  
        }
        return 0;
	}
	public function latestonline()
	{
		$this->db->select('customer_order.*,customer_info.customer_name,customer_info.customer_phone,rest_table.tablename');
        $this->db->from('customer_order');
		$this->db->join('customer_info','customer_order.customer_id=customer_info.customer_id','left');
		$this->db->join('rest_table','customer_order.table_no=rest_table.tableid','left');
		$this->db->where('order_status!=', 5);
		$this->db->where('cutomertype', 2);
		$this->db->order_by('saleinvoice', 'DESC');
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->result();  
        }
        return false;
	}
	public function latestreservation()
	{
		$this->db->select('tblreservation.*,customer_info.customer_name,customer_info.customer_phone,rest_table.tablename');
        $this->db->from('tblreservation');
		$this->db->join('customer_info','tblreservation.cid=customer_info.customer_id','left');
		$this->db->join('rest_table','tblreservation.tableid=rest_table.tableid','left');
		$this->db->where('tblreservation.status', 2);
		$this->db->order_by('tblreservation.reserveday', 'DESC');
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->result();  
        }
        return false;
	}
	public function latestpending()
	{
		$this->db->select('customer_order.*,customer_info.customer_name,customer_info.customer_phone,rest_table.tablename');
        $this->db->from('customer_order');
		$this->db->join('customer_info','customer_order.customer_id=customer_info.customer_id','left');
		$this->db->join('rest_table','customer_order.table_no=rest_table.tableid','left');
		$this->db->where('order_status', 1);
		$this->db->order_by('saleinvoice', 'DESC');
        $query = $this->db->get();
        if ($query->num_rows() > 0) {
            return $query->result();  
        }
        return false;
	}
	
	public function monthlybookingamount($year,$month)
		{
			$groupby="GROUP BY YEAR(date_time), MONTH(date_time)";
			$amount='';
			$wherequery = "YEAR(date_time)='$year' AND month(date_time)='$month' AND total_price=paid_amount GROUP BY YEAR(date_time), MONTH(date_time)";
			$this->db->select('SUM(total_price) as amount');
			$this->db->from('booked_info');
			$this->db->where($wherequery, NULL, FALSE);
			$query = $this->db->get();
			if ($query->num_rows() > 0) {
				$result=$query->result(); 
				foreach($result as $row){
					$amount.=$row->amount.", ";
					}
				return trim($amount,', ');
			}
			return 0;
		}
	public function monthlybookingorder($year,$month)
		{
			$groupby="GROUP BY YEAR(date_time), MONTH(date_time)";
			$totalorder='';
			$wherequery = "YEAR(date_time)='$year' AND month(date_time)='$month' AND bookingstatus=5 GROUP BY YEAR(date_time), MONTH(date_time)";
			$this->db->select('count(bookedid) as totalorder');
			$this->db->from('booked_info');
			$this->db->where($wherequery, NULL, FALSE);
			$query = $this->db->get();
			if ($query->num_rows() > 0) {
				$result=$query->result(); 
				foreach($result as $row){
					$totalorder.=$row->totalorder.", ";
					}
				return trim($totalorder,', ');
			}
			return 0;
		}
		public function monthlybookingpending($year,$month)
		{
			$groupby="GROUP BY YEAR(date_time), MONTH(date_time)";
			$totalorder='';
			$wherequery = "YEAR(date_time)='$year' AND month(date_time)='$month' AND bookingstatus=0 GROUP BY YEAR(date_time), MONTH(date_time)";
			$this->db->select('count(bookedid) as totalorder');
			$this->db->from('booked_info');
			$this->db->where($wherequery, NULL, FALSE);
			$query = $this->db->get();
			if ($query->num_rows() > 0) {
				$result=$query->result(); 
				foreach($result as $row){
					$totalorder.=$row->totalorder.", ";
					}
				return trim($totalorder,', ');
			}
			return 0;
		}
		public function monthlybookingcancel($year,$month)
		{
			$groupby="GROUP BY YEAR(date_time), MONTH(date_time)";
			$totalorder='';
			$wherequery = "YEAR(date_time)='$year' AND month(date_time)='$month' AND bookingstatus=1 GROUP BY YEAR(date_time), MONTH(date_time)";
			$this->db->select('count(bookedid) as totalorder');
			$this->db->from('booked_info');
			$this->db->where($wherequery, NULL, FALSE);
			$query = $this->db->get();
			if ($query->num_rows() > 0) {
				$result=$query->result(); 
				foreach($result as $row){
					$totalorder.=$row->totalorder.", ";
					}
				return trim($totalorder,', ');
			}
			return 0;
		}
		public function monthlybookingtotal($year,$month)
		{
			$groupby="GROUP BY YEAR(date_time), MONTH(date_time)";
			$totalorder='';
			$wherequery = "YEAR(date_time)='$year' AND month(date_time)='$month' AND bookingstatus!=1 GROUP BY YEAR(date_time), MONTH(date_time)";
			$this->db->select('count(bookedid) as totalorder');
			$this->db->from('booked_info');
			$this->db->where($wherequery, NULL, FALSE);
			$query = $this->db->get();
			if ($query->num_rows() > 0) {
				$result=$query->result(); 
				foreach($result as $row){
					$totalorder.=$row->totalorder.", ";
					}
				return trim($totalorder,', ');
			}
			return 0;
		}
	public function onlinesaleamount($year,$month)
		{
			$groupby="GROUP BY YEAR(order_date), MONTH(order_date)";
			$amount='';
			$wherequery = "YEAR(order_date)='$year' AND month(order_date)='$month' AND cutomertype=2 AND order_status!=5 GROUP BY YEAR(order_date), MONTH(order_date)";
			$this->db->select('SUM(totalamount) as amount');
			$this->db->from('customer_order');
			$this->db->where($wherequery, NULL, FALSE);
			$query = $this->db->get();
			if ($query->num_rows() > 0) {
				$result=$query->result(); 
				foreach($result as $row){
					$amount.=$row->amount.", ";
					}
				return trim($amount,', ');
			}
			return 0;
		}
	public function onlinesaleorder($year,$month)
		{
			$groupby="GROUP BY YEAR(order_date), MONTH(order_date)";
			$totalorder='';
			$wherequery = "YEAR(order_date)='$year' AND month(order_date)='$month' AND cutomertype=2 AND order_status!=5 GROUP BY YEAR(order_date), MONTH(order_date)";
			$this->db->select('count(order_id) as totalorder');
			$this->db->from('customer_order');
			$this->db->where($wherequery, NULL, FALSE);
			$query = $this->db->get();
			if ($query->num_rows() > 0) {
				$result=$query->result(); 
				foreach($result as $row){
					$totalorder.=$row->totalorder.", ";
					}
				return trim($totalorder,', ');
			}
			return 0;
		}
	
	public function offlinesaleamount($year,$month)
		{
			$groupby="GROUP BY YEAR(order_date), MONTH(order_date)";
			$amount='';
			$wherequery = "YEAR(order_date)='$year' AND month(order_date)='$month' AND cutomertype=1 AND order_status!=5 GROUP BY YEAR(order_date), MONTH(order_date)";
			$this->db->select('SUM(totalamount) as amount');
			$this->db->from('customer_order');
			$this->db->where($wherequery, NULL, FALSE);
			$query = $this->db->get();
			if ($query->num_rows() > 0) {
				$result=$query->result(); 
				foreach($result as $row){
					$amount.=$row->amount.", ";
					}
				return trim($amount,', ');
			}
			return 0;
		}
	public function offlinesaleorder($year,$month)
		{
			$groupby="GROUP BY YEAR(order_date), MONTH(order_date)";
			$totalorder='';
			$wherequery = "YEAR(order_date)='$year' AND month(order_date)='$month' AND cutomertype=1 AND order_status!=5 GROUP BY YEAR(order_date), MONTH(order_date)";
			$this->db->select('count(order_id) as totalorder');
			$this->db->from('customer_order');
			$this->db->where($wherequery, NULL, FALSE);
			$query = $this->db->get();
			if ($query->num_rows() > 0) {
				$result=$query->result(); 
				foreach($result as $row){
					$totalorder.=$row->totalorder.", ";
					}
				return trim($totalorder,', ');
			}
			return 0;
		}


	/**
	 * Jenis usaha instance ini (hotel/resto) dari registry tenant di DB kontrol.
	 * NULL bila DB aktif bukan DB tenant (mis. oversight Bapenda).
	 */
	public function jenis_usaha()
	{
		$ctrl = $this->load->database('control', TRUE);
		$row = $ctrl->select('jenis_usaha')->where('db_name', $this->db->database)->get('tenants')->row();
		return $row ? $row->jenis_usaha : null;
	}

	/** Ringkasan dashboard restoran. Status order: 1 pending, 2 proses, 3 siap, 4 selesai, 5 batal. */
	public function resto_summary()
	{
		$today = date('Y-m-d');
		$month = date('Y-m');
		$one = function ($sql, $bind = array()) {
			return $this->db->query($sql, $bind)->row();
		};
		return (object) array(
			'all'       => $one("SELECT COUNT(*) n, COALESCE(SUM(totalamount),0) v FROM customer_order WHERE order_status = 4"),
			'running'   => $one("SELECT COUNT(*) n, COALESCE(SUM(totalamount),0) v FROM customer_order WHERE order_status IN (1,2,3)"),
			'cancel'    => $one("SELECT COUNT(*) n FROM customer_order WHERE order_status = 5"),
			'today'     => $one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN order_status = 4 THEN totalamount END),0) v FROM customer_order WHERE order_date = ? AND order_status <> 5", array($today)),
			// Sama dengan dasar omzet engine PBJT: semua order yang tidak batal.
			// dpp/pbjt per transaksi dari engine PBJT (sama dengan menu Pengawasan PBJT).
			// (sebelum migrasi 010 kolom dpp/pbjt belum ada: tampilkan omzet, PBJT 0)
			'month'     => $this->db->field_exists('pbjt', 'customer_order')
				? $one("SELECT COUNT(*) n, COALESCE(SUM(dpp),0) v, COALESCE(SUM(pbjt),0) p FROM customer_order WHERE DATE_FORMAT(order_date,'%Y-%m') = ? AND order_status <> 5", array($month))
				: $one("SELECT COUNT(*) n, COALESCE(SUM(totalamount),0) v, 0 p FROM customer_order WHERE DATE_FORMAT(order_date,'%Y-%m') = ? AND order_status <> 5", array($month)),
		);
	}

	public function resto_monthly($months = 6)
	{
		$from = date('Y-m-01', strtotime('-' . ((int) $months - 1) . ' month'));
		return $this->db->query("SELECT DATE_FORMAT(order_date,'%Y-%m') ym, COUNT(*) n, COALESCE(SUM(totalamount),0) v
			FROM customer_order WHERE order_status = 4 AND order_date >= ? GROUP BY ym ORDER BY ym", array($from))->result();
	}

	public function resto_latest($limit = 10)
	{
		return $this->db->query("SELECT o.saleinvoice, o.order_date, o.order_time, o.totalamount, o.order_status, t.tablename
			FROM customer_order o LEFT JOIN rest_table t ON t.tableid = o.table_no
			ORDER BY o.order_id DESC LIMIT " . (int) $limit)->result();
	}
}
 