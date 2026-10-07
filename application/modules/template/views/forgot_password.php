<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- The above 3 meta tags *must* come first in the head; any other head content must come *after* these tags -->
    <title><?php echo (!empty($setting->title)?$setting->title:null) ?> :: <?php echo (!empty($title)?$title:null) ?>
    </title>
    <!-- Favicon and touch icons -->
    <link rel="shortcut icon"
        href="<?php echo base_url((!empty($setting->favicon) ? $setting->favicon : 'assets/img/fav.png')) ?>"
        type="image/x-icon">
    <!-- Start Global Mandatory Style -->
    <!-- Bootstrap -->
    <link href="<?php echo base_url('assets/plugins/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet"
        type="text/css" />
    <!-- Bootstrap rtl -->
    <link href="<?php echo base_url('assets/plugins/fontawesome/css/all.min.css') ?>" rel="stylesheet"
        type="text/css" />
    <link href="<?php echo base_url('assets/plugins/themify-icons/themify-icons.css') ?>" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style.css?v=4') ?>" rel="stylesheet" type="text/css" />
    <!-- Sweet Alert -->
    <link href="<?php echo base_url('assets/sweetalert/sweetalert.css') ?>" rel="stylesheet" type="text/css"/>
    <!-- PRO THEME: senada halaman login (markup & alur tidak diubah) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/pro-theme.css?v=1') ?>" rel="stylesheet" type="text/css"/>
    <style>
        body.body-bg{min-height:100vh;background:radial-gradient(900px 500px at 0% 0%,rgba(45,212,191,.18),transparent 60%),radial-gradient(900px 500px at 100% 100%,rgba(16,185,129,.16),transparent 60%),#f4f6fa !important}
        .bg-img-hero{display:none}
        .news365-logo img{max-height:46px;width:auto}
        .form-card{background:#fff;border:1px solid var(--pt-border);border-radius:20px;box-shadow:var(--pt-shadow-lg);padding:34px 32px}
        .form-card h1.display-4{font-size:26px !important;font-weight:800 !important;letter-spacing:-.02em;margin-bottom:6px}
        .form-card .text-center p{color:var(--pt-muted);font-size:14.5px}
        .form-card .form-control{height:48px;border-radius:12px !important}
        .forgot-custom-btn{width:100%;height:48px;border:0;border-radius:12px !important;font-weight:700;color:#fff;background:linear-gradient(135deg,var(--pt-primary),#059669) !important}
        .forgot-custom-btn a{color:#fff !important;text-decoration:none}
    </style>

</head>

<body class="bg-white body-bg">
<input type="hidden" id='csrf_token' value="<?php echo $this->security->get_csrf_hash();?>" />
    <input type="hidden" id="base" val="<?php echo base_url(); ?>">
    <main class="register-content">
        <div class="bg-img-hero position-fixed top-0 right-0 left-0">
            <figure class="position-absolute right-0 bottom-0 left-0 m-0">
                <img src="assets/img/fig.svg" data-pagespeed-url-hash="2593638024">
            </figure>
        </div>
        <div class="container py-5 py-sm-7">
            <a class="d-flex justify-content-center mb-5 news365-logo" href="">
                <img class="z-index-2"
                src="<?php echo html_escape(base_url((!empty($web_setting->login_logo) ? $web_setting->login_logo : 'assets/img/login_logo.png'))) ?>"
                    alt="Image Description" data-pagespeed-url-hash="799927880">
            </a>
            <div class="row justify-content-center">
                <div class="col-md-7 col-lg-5">
                    <div class="">
                        <!-- alert message -->
                        <?php if ($this->session->flashdata('message') != null) {  ?>
                        <div class="alert alert-info alert-dismissable">
                            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                            <?php echo $this->session->flashdata('message'); ?>
                        </div>
                        <?php } ?>
                        <?php if ($this->session->flashdata('exception') != null) {  ?>
                        <div class="alert alert-danger alert-dismissable">
                            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                            <?php echo $this->session->flashdata('exception'); ?>
                        </div>
                        <?php } ?>
                        <?php if (validation_errors()) {  ?>
                        <div class="alert alert-danger alert-dismissable">
                            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                            <?php echo validation_errors(); ?>
                        </div>
                        <?php } ?>
                    </div>
                    <div class="form-card mb-5">
                        <div class="form-card_body">
                            <div class="text-center">
                                <div class="mb-5">
                                    <h1 class="display-4 mt-0 font-weight-semi-bold"><?php echo display('forgot_password') ?>
                                    </h1>
                                    <p><?php echo display('receover_password') ?></p>
                                </div>
                            </div>
                            <div class="form-group" id="main_email">
                                <label class="input-label font-weight-bold"
                                    for="inputEmail"><?php echo display('y_mail') ?></label>
                                <input type="email" name="email" autocomplete="off" id="inputEmail"
                                    placeholder="<?php echo display('email') ?>" required autofocus
                                    class="form-control">
                            </div>
                            <div class="mb-5" id="sent">
                                <p id="msg_email"></p>
                            </div>
                            <div class="form-group" id="sent1">
                                <label class="input-label font-weight-bold"
                                    for="code"><?php echo display('enter_code') ?></label>
                                <input type="text" name="code" autocomplete="off" id="code"
                                    placeholder="<?php echo display('enter_code') ?>" required autofocus
                                    class="form-control">
                            </div>
                            <div class="form-group" id="new_password">
                                <label class="input-label font-weight-bold"
                                    for="new_pass"><?php echo display('new_pass') ?></label>
                                <input type="password" name="new_pass" autocomplete="off" id="new_pass"
                                    placeholder="<?php echo display('new_pass') ?>" required autofocus
                                    class="form-control">
                                <input type="hidden" name="hemail" autocomplete="off" id="hemail"
                                    placeholder="<?php echo display('hemail') ?>" required autofocus
                                    class="form-control">
                            </div>
                            <div class="form-group">
                                <!-- capta part -->
                                <button onclick="forgot_password()" type="submit" id="email_btn"
                                    class="right forgot-content forgot-custom-btn btn-success"><?php echo display('submit') ?></button>
                                <button onclick="submit_code()" type="submit" id="otp_btn"
                                    class="right forgot-content forgot-custom-btn btn btn-success"><?php echo display('submit') ?></button>
                                <button onclick="reset_password()" type="submit" id="reset_btn"
                                    class="right forgot-content forgot-custom-btn btn btn-success"><?php echo display('submit') ?></button>
                                <button type="submit" id="login_btn"
                                    class="right forgot-content forgot-custom-btn btn btn-success"><a style="color: #fff;" href='login'><?php echo display('login') ?></a></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
        <!-- jQuery -->
        <script src="<?php echo base_url(); ?>assets/plugins/jQuery/jquery.min.js"></script>
    <!-- Bootstrap -->
    <script src="<?php echo base_url(); ?>assets/plugins/bootstrap/js/popper.min.js"></script>
    <script src="<?php echo base_url('assets/plugins/bootstrap/js/bootstrap.min.js') ?>" type="text/javascript">
    </script>
    <script src="<?php echo base_url('assets/js/login.js') ?>" type="text/javascript"></script>
    <script src="<?php echo base_url('assets/js/password.js') ?>" type="text/javascript"></script>
    
<!-- sweetalert -->
<script src="<?php echo base_url('assets/sweetalert/sweetalert.min.js') ?>" type="text/javascript"></script>
</body>

</html>
<script src="<?php echo base_url() ?>assets/js/forgot-password.js"></script>