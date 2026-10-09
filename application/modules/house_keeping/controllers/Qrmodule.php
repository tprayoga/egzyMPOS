<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Qrmodule extends MX_Controller
{

    public function __construct()
    {
        parent::__construct();
        $this->load->model(array(
            'qrmodule_model'
		));
    }

    public function index()
    {
        $data['title'] = display('module_list');
        $data['module'] = "qrapp";
        $data['page'] = "qrorder";
        echo Modules::run('template/layout', $data);
    }
	public function allqrorder(){
		$list = $this->qrmodule_model->get_qronlineorder();
		$data = array();
		$no = $_POST['start'];
		foreach ($list as $rowdata) {
			if($rowdata->bill_status==1){
			$paymentyst="Paid";
			}
			else{$paymentyst="Unpaid";}
			$no++;
			$row = array();
			$update='';
			$print='';
			$details='';
			$paymentbtn='';
			$cancelbtn='';
			$rejectbtn='';
			$posprint='';
			
			if($this->permission->method('ordermanage','read')->access()):
			$details='&nbsp;<a onclick="detailspop('.$rowdata->order_id.')" class="btn btn-xs btn-success btn-sm mr-1" data-placement="left" title="" data-original-title="Details" data-toggle="modal" data-target="#orderdetailsp" data-dismiss="modal"><i class="fa fa-eye"></i></a>&nbsp;';
			$posprint='<a onclick="pospageprint('.$rowdata->order_id.')" class="btn btn-xs btn-success btn-sm mr-1" data-toggle="tooltip" data-placement="left" title="" data-original-title="Pos Invoice"><i class="fa fa-window-maximize"></i></a>';
			endif;
			$row[] = $no;
			$row[] = $rowdata->saleinvoice;
			$row[] = $rowdata->customer_name;
			$row[] = "QR Customer";
			$row[] = $rowdata->first_name.$rowdata->last_name;
			$row[] = $rowdata->tablename;
			$row[] = $paymentyst;
			$row[] = $rowdata->order_date;
			$row[] = $rowdata->totalamount;
			$row[] =$cancelbtn.$rejectbtn.$paymentbtn.$update.$posprint.$details;
			$row[] = $rowdata->isupdate;
			$data[] = $row;
		}
		$output = array(
						"draw" => $_POST['draw'],
						"recordsTotal" => $this->qrmodule_model->count_allqrorder(),
						"recordsFiltered" => $this->qrmodule_model->count_filtertqrorder(),
						"data" => $data,
				);
		echo json_encode($output);
		
		}
	public function tableqrcode(){
		$data['title'] = display('module_list');
		$data['tablelist']   = $this->qrmodule_model->tablelist();
        $data['module'] = "qrapp";
        $data['page'] = "tableqr";
        echo Modules::run('template/layout', $data);
		}
	public function roomqrcode(){
		$data['title'] = display('module_list');
		$data['roomlist']   = $this->qrmodule_model->roomlist();
		// QR dibuat di server: layanan Google Chart (chart.apis.google.com) sudah dimatikan,
		// sehingga gambar dulu tampil rusak.
		foreach ($data['roomlist'] as $room) {
			$room->qr = $this->_qr_data_uri($room->roomno);
		}
        $data['module'] = "house_keeping";
        $data['page'] = "roomqr";
        echo Modules::run('template/layout', $data);
		}

	private function _qr_data_uri($text){
		$this->load->library('ciqrcode', array('cacheable' => false));
		$tmp = tempnam(sys_get_temp_dir(), 'qr');
		$this->ciqrcode->generate(array('data' => (string) $text, 'level' => 'H', 'size' => 6, 'savename' => $tmp));
		$png = (string) @file_get_contents($tmp);
		@unlink($tmp);
		return 'data:image/png;base64,' . base64_encode($png);
	}

   

}
