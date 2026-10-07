<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="card" style="max-width:640px;margin:40px auto">
    <div class="card-body" style="text-align:center;padding:44px 32px !important">
        <div style="width:64px;height:64px;border-radius:18px;margin:0 auto 18px;display:grid;place-items:center;background:var(--pt-primary-tint,#e7f5f2);color:var(--pt-primary,#0f766e)">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
        </div>
        <h4 style="font-weight:800;margin:0 0 8px"><?php echo html_escape($infoTitle) ?></h4>
        <p class="text-muted" style="font-size:14px;margin:0 auto 22px;max-width:440px"><?php echo html_escape($infoMsg) ?></p>
        <a class="btn btn-primary" href="<?php echo base_url('pbjt') ?>">Ke Pengawasan PBJT</a>
        <a class="btn btn-light" href="<?php echo base_url('dashboard/home') ?>">Ke Dashboard</a>
    </div>
</div>
