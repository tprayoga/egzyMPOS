<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo html_escape((!empty($setting->title) ? $setting->title : null)) ?> ::
        <?php echo html_escape((!empty($title) ? $title : null)) ?></title>
    <link rel="shortcut icon"
        href="<?php echo base_url((!empty($setting->favicon) ? $setting->favicon : 'assets/img/fav.png')) ?>"
        type="image/x-icon">
    <link href="<?php echo base_url('assets/plugins/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?php echo base_url('assets/plugins/fontawesome/css/all.min.css') ?>" rel="stylesheet" type="text/css" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--p:#0f766e;--p2:#059669;--p7:#115e59;--ink:#0f172a;--ink2:#334155;--muted:#64748b;--line:#e2e8f0;--bg:#f4f6fa}
        *{box-sizing:border-box}
        html,body{height:100%}
        body{margin:0;font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:var(--ink);background:var(--bg);-webkit-font-smoothing:antialiased}
        .auth{min-height:100vh;display:grid;grid-template-columns:1.05fr 1fr}
        @media(max-width:900px){.auth{grid-template-columns:1fr}.auth-brand{display:none}}

        /* Panel brand */
        .auth-brand{position:relative;overflow:hidden;color:#fff;padding:56px 60px;display:flex;flex-direction:column;justify-content:space-between;
            background:radial-gradient(1200px 600px at -10% -10%,rgba(45,212,191,.35),transparent 60%),
                       radial-gradient(900px 500px at 110% 110%,rgba(16,185,129,.35),transparent 60%),
                       linear-gradient(145deg,#0b1320 0%,#0f2a2c 55%,#0f766e 100%)}
        .auth-brand::after{content:"";position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:44px 44px;mask-image:linear-gradient(180deg,rgba(0,0,0,.9),transparent 85%);pointer-events:none}
        .brand-top{position:relative;z-index:1;display:flex;align-items:center;gap:12px}
        .brand-top img{max-height:40px;width:auto;background:rgba(255,255,255,.95);padding:6px 10px;border-radius:12px}
        .brand-mid{position:relative;z-index:1;max-width:520px}
        .eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;
            background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);padding:6px 12px;border-radius:999px;margin-bottom:22px}
        .eyebrow .dot{width:7px;height:7px;border-radius:50%;background:#2dd4bf;box-shadow:0 0 0 4px rgba(45,212,191,.25)}
        .brand-mid h1{font-size:38px;line-height:1.15;font-weight:800;letter-spacing:-.03em;margin:0 0 16px;color:#fff}
        .brand-mid p{font-size:15.5px;line-height:1.65;color:rgba(255,255,255,.78);margin:0 0 30px}
        .feat{display:grid;gap:14px}
        .feat div{display:flex;gap:12px;align-items:flex-start;font-size:14px;color:rgba(255,255,255,.88)}
        .feat svg{flex:none;margin-top:1px}
        .brand-foot{position:relative;z-index:1;font-size:12.5px;color:rgba(255,255,255,.55)}

        /* Panel form */
        .auth-form{display:flex;align-items:center;justify-content:center;padding:40px 24px;background:#fff}
        .card-auth{width:100%;max-width:420px}
        .mobile-logo{display:none;margin-bottom:26px}
        .mobile-logo img{max-height:44px}
        @media(max-width:900px){.mobile-logo{display:block}}
        .card-auth h2{font-size:26px;font-weight:800;letter-spacing:-.02em;margin:0 0 6px}
        .card-auth .lead{color:var(--muted);font-size:14.5px;margin:0 0 28px}
        .field{margin-bottom:18px}
        .field label{display:block;font-size:13px;font-weight:600;color:var(--ink2);margin-bottom:7px}
        .input{position:relative}
        .input .ico{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none}
        .input input{width:100%;height:48px;border:1px solid #d8dde6;border-radius:12px;padding:0 44px 0 42px;font-size:14.5px;font-family:inherit;color:var(--ink);background:#fbfcfd;transition:border-color .15s,box-shadow .15s,background .15s}
        .input input:focus{outline:none;border-color:var(--p);background:#fff;box-shadow:0 0 0 4px rgba(15,118,110,.14)}
        .input .toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#94a3b8;width:36px;height:36px;border-radius:8px;cursor:pointer}
        .input .toggle:hover{background:#f1f5f9;color:var(--ink2)}
        .captcha-row{display:grid;gap:10px}
        .captcha-img{justify-self:start;max-width:100%;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#fff;line-height:0}
        .captcha-img img{height:52px;width:auto;max-width:100%;display:block}
        .captcha-row .input input{padding-left:14px}
        .row-between{display:flex;justify-content:flex-end;margin:-4px 0 22px}
        .row-between a{font-size:13px;font-weight:600;color:var(--p);text-decoration:none}
        .row-between a:hover{color:var(--p7);text-decoration:underline}
        .btn-login{width:100%;height:50px;border:0;border-radius:12px;font-weight:700;font-size:15px;color:#fff;cursor:pointer;
            background:linear-gradient(135deg,var(--p),var(--p2));box-shadow:0 8px 20px -8px rgba(15,118,110,.6);transition:transform .08s,box-shadow .15s,filter .15s}
        .btn-login:hover{filter:brightness(1.05);box-shadow:0 12px 26px -8px rgba(15,118,110,.7)}
        .btn-login:active{transform:translateY(1px)}
        .alert{border-radius:12px;font-size:13.5px;padding:12px 40px 12px 14px;margin-bottom:18px;border:1px solid transparent;position:relative}
        .alert-info{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
        .alert-danger{background:#fef2f2;border-color:#fecaca;color:#991b1b}
        .alert .close{position:absolute;right:12px;top:9px;background:none;border:0;font-size:20px;opacity:.5;cursor:pointer}
        .alert p{margin:0}
        .secure{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:26px;font-size:12.5px;color:var(--muted)}
    </style>
</head>

<body>
    <main class="auth">
        <!-- Panel brand -->
        <section class="auth-brand">
            <div class="brand-top">
                <img src="<?php echo html_escape(base_url((!empty($web_setting->login_logo) ? $web_setting->login_logo : 'assets/img/login_logo.png'))) ?>" alt="Logo">
            </div>
            <div class="brand-mid">
                <span class="eyebrow"><span class="dot"></span>Sistem Operasional &amp; Pengawasan PBJT</span>
                <h1>Kelola usaha Anda. Transparan untuk daerah.</h1>
                <p>Satu platform untuk operasional hotel &amp; restoran &mdash; reservasi, POS, SDM, hingga laporan &mdash; yang terhubung dengan pengawasan pajak daerah.</p>
                <div class="feat">
                    <div><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2dd4bf" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg>Data setiap usaha terisolasi di database sendiri</div>
                    <div><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2dd4bf" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg>Hak akses berbasis peran untuk tim Anda</div>
                    <div><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2dd4bf" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/></svg>Pelaporan pajak otomatis dari transaksi</div>
                </div>
            </div>
            <div class="brand-foot">&copy; <?php echo date('Y') ?> <?php echo html_escape((!empty($setting->title) ? $setting->title : '')) ?></div>
        </section>

        <!-- Panel form -->
        <section class="auth-form">
            <div class="card-auth">
                <div class="mobile-logo">
                    <img src="<?php echo html_escape(base_url((!empty($web_setting->login_logo) ? $web_setting->login_logo : 'assets/img/login_logo.png'))) ?>" alt="Logo">
                </div>
                <h2>Masuk ke akun Anda</h2>
                <p class="lead">Gunakan email terdaftar. Sistem otomatis mengarahkan ke instance usaha Anda.</p>

                <?php if ($this->session->flashdata('message') != null) { ?>
                <div class="alert alert-info"><button type="button" class="close" onclick="this.parentNode.remove()">&times;</button><?php echo $this->session->flashdata('message'); ?></div>
                <?php } ?>
                <?php if ($this->session->flashdata('exception') != null) { ?>
                <div class="alert alert-danger"><button type="button" class="close" onclick="this.parentNode.remove()">&times;</button><?php echo $this->session->flashdata('exception'); ?></div>
                <?php } ?>
                <?php if (validation_errors()) { ?>
                <div class="alert alert-danger"><button type="button" class="close" onclick="this.parentNode.remove()">&times;</button><?php echo validation_errors(); ?></div>
                <?php } ?>

                <?php echo form_open('login', 'id="loginForm" novalidate'); ?>
                <div class="field">
                    <label for="inputEmail"><?php echo display("your_email") ?></label>
                    <div class="input">
                        <svg class="ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
                        <input type="email" name="email" autocomplete="username" id="inputEmail" placeholder="nama@usaha.com" required autofocus>
                    </div>
                </div>
                <div class="field">
                    <label for="inputPassword"><?php echo display('password') ?></label>
                    <div class="input">
                        <svg class="ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        <input type="password" name="password" id="inputPassword" placeholder="••••••••" autocomplete="current-password" required>
                        <button type="button" class="toggle" onclick="ptTogglePass(this)" aria-label="Tampilkan kata sandi">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>
                <div class="field">
                    <label for="captcha"><?php echo display('captcha') ?></label>
                    <div class="captcha-row">
                        <div class="captcha-img"><?php echo $captcha_image ?></div>
                        <div class="input">
                            <input type="text" name="captcha" id="captcha" placeholder="Ketik kode" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="row-between"><a href="forgot-password"><?php echo display('forgot_password') ?></a></div>
                <button type="submit" class="btn-login"><?php echo display('login') ?></button>
                <?php echo form_close() ?>

                <div class="secure">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/></svg>
                    Koneksi terlindungi &middot; data usaha terisolasi
                </div>
            </div>
        </section>
    </main>
    <script src="<?php echo base_url(); ?>assets/plugins/jQuery/jquery.min.js"></script>
    <script src="<?php echo base_url(); ?>assets/plugins/bootstrap/js/popper.min.js"></script>
    <script src="<?php echo base_url('assets/plugins/bootstrap/js/bootstrap.min.js') ?>" type="text/javascript"></script>
    <script>
        function ptTogglePass(btn) {
            var x = document.getElementById('inputPassword');
            x.type = (x.type === 'password') ? 'text' : 'password';
            btn.style.color = (x.type === 'text') ? '#0f766e' : '';
        }
    </script>
</body>

</html>
