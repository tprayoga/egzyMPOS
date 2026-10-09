<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- The above 3 meta tags *must* come first in the head; any other head content must come *after* these tags -->
<title><?php echo html_escape((!empty($setting->title)?$setting->title:'EGZY')) ?> - <?php echo html_escape((!empty($title)?$title:'Data List')) ?></title>

<!-- Favicon and touch icons -->
<link rel="shortcut icon" href="<?php echo html_escape(base_url((!empty($setting->favicon)?$setting->favicon:'assets/img/fav.png'))) ?>" type="image/x-icon">

<!-- Start Global Mandatory Style -->
<!-- jquery-ui css -->
<link href="<?php echo egzy_asset('assets/plugins/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/plugins/bootstrap/css/datatables.min.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/plugins/bootstrap4-toggle/css/bootstrap4-toggle.min.css') ?>" rel="stylesheet" type="text/css"/>
<!-- Bootstrap -->
<link href="<?php echo egzy_asset('assets/plugins/pace/flash.css') ?>" rel="stylesheet" type="text/css"/>
<!-- Bootstrap tag input-->
<link href="<?php echo egzy_asset('assets/plugins/metisMenu/metisMenu.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/plugins/fontawesome/css/all.min.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/plugins/typicons/src/typicons.min.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/plugins/themify-icons/themify-icons.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/plugins/select2/dist/css/select2.min.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/plugins/bootstrap/css/bootstrap-select.css') ?>" rel="stylesheet" type="text/css"/>
<!-- Bootstrap rtl -->
<?php if (($setting->site_align=='RTL')) { ?>
<?php } ?>

<!-- Lobipanel css -->
<link href="<?php echo egzy_asset('assets/plugins/material_icons/materia_icons.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/plugins/bootstrap/css/bootstrap-material-datetimepicker.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/plugins/daterangepicker/daterangepicker.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/plugins/jquery.sumoselect/sumoselect.min.css') ?>" rel="stylesheet" type="text/css"/>

<!-- Sweet Alert -->
<link href="<?php echo egzy_asset('assets/sweetalert/sweetalert.css') ?>" rel="stylesheet" type="text/css"/>
<!-- timepicker -->
<link href="<?php echo egzy_asset('assets/css/jquery-ui-timepicker-addon.min.css') ?>" rel="stylesheet" type="text/css"/>
<!-- Pace css -->
<!-- Themify icons -->
<link href="<?php echo egzy_asset('assets/plugins/jquery-ui-1.12.1/jquery-ui.min.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/css/custom-style.css') ?>" rel="stylesheet" type="text/css"/>
<!-- Kitchen Css -->
<link href="<?php echo egzy_asset('assets/css/kitchen.css') ?>" rel="stylesheet" type="text/css"/>

<link href="<?php echo egzy_asset('assets/plugins/toastr/toastr.css') ?>" rel="stylesheet" type="text/css"/>
<?php  $path = 'application/modules/';
        $map = directory_map($path);
        $modnames = array();
        if (is_array($map) && sizeof($map) > 0){
        $modnames = array_filter(array_keys($map));
        $modnames = preg_replace('/\W/', '', $modnames);
        }
        if (in_array("ordermanage", $modnames) === true) {
           
            ?>
<link href="<?php echo egzy_asset('/application/modules/ordermanage/assets/css/print.css') ?>" rel="stylesheet" type="text/css"/>
<?php } ?>
<link href="<?php echo egzy_asset('assets/plugins/toastr/toastr.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/css/style.css') ?>" rel="stylesheet" type="text/css"/>
<link href="<?php echo egzy_asset('assets/css/custom-style.css') ?>" rel="stylesheet" type="text/css"/>

<!-- Google fonts -->
<link href= "https://fonts.googleapis.com/css?family=Nunito+Sans:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i&display=swap" rel="stylesheet" />

<!-- Theme style rtl -->
<?php if (($setting->site_align=='RTL')) { ?>
<?php } ?>
<!-- Include module style -->
<?php
    $path = 'application/modules/';
    $map  = directory_map($path);
    if (is_array($map) && sizeof($map) > 0)
    foreach ($map as $key => $value) {
        $css  = str_replace("\\", '/', $path.$key.'assets/css/style.css');  
        if (file_exists($css)) {
            echo "<link href=\"".egzy_asset($css)."\" rel=\"stylesheet\">";
        }
    }
?>
<!-- PRO THEME: dimuat paling akhir agar menimpa seluruh style lama -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?php echo egzy_asset('assets/css/pro-theme.css') ?>" rel="stylesheet" type="text/css"/>
<!-- jQuery -->
<script src="<?php echo base_url();?>assets/plugins/jQuery/jquery.min.js"></script>
<!-- print func -->
<script src="<?php echo base_url();?>assets/js/print.js"></script>