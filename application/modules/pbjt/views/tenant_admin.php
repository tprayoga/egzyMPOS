<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/* Konten kelola tenant — dirender di dalam template/layout. Pesan sukses/gagal tampil sebagai toast layout. */
if (!function_exists('ta_h')) { function ta_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
$base = rtrim(base_url(), '/');
?>
<div class="pbjt-head">
    <div>
        <div class="eyebrow"><span class="dot"></span>Pengawasan PBJT &middot; Administrasi</div>
        <h2>Kelola Tenant (WP) <span class="pbjt-role">Bapenda</span></h2>
        <p>Daftarkan wajib pajak baru &mdash; sistem menyiapkan database usahanya, admin operasional, dan pengawasannya.</p>
    </div>
    <div class="pbjt-actions">
        <a class="btn btn-light" href="<?= $base ?>/pbjt">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>Kembali ke Pengawasan
        </a>
    </div>
</div>

<div class="row">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h6>Daftarkan WP Baru</h6></div>
            <div class="card-body">
                <form method="post" action="<?= $base ?>/pbjt/simpan"
                      onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='Memproses (±6 detik)…';">
                    <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>" value="<?= $this->security->get_csrf_hash() ?>">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Kode WP (NPWPD) <span class="text-danger">*</span></label>
                            <input class="form-control" name="wp_id" required placeholder="mis. WP011" pattern="[A-Za-z0-9]+">
                        </div>
                        <div class="form-group col-md-6">
                            <label>Jenis usaha</label>
                            <select class="form-control" name="jenis"><option value="hotel">Hotel</option><option value="resto">Restoran</option></select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Nama usaha <span class="text-danger">*</span></label>
                        <input class="form-control" name="nama" required placeholder="mis. Hotel Cempaka">
                    </div>
                    <div class="form-group">
                        <label>Kecamatan <span class="text-danger">*</span></label>
                        <input class="form-control" name="kecamatan" required placeholder="mis. Sukajadi">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-7">
                            <label>Email admin WP <span class="text-danger">*</span></label>
                            <input class="form-control" type="email" name="email" required placeholder="admin@usaha.id">
                        </div>
                        <div class="form-group col-md-5">
                            <label>Password</label>
                            <input class="form-control" name="password" placeholder="kosongkan = dibuat acak (min. 8 karakter)">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Buat Tenant</button>
                    <p class="pbjt-form-note">Kode WP harus unik. Pembuatan database memerlukan beberapa detik.</p>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h6>Tenant Terdaftar <small class="float-right"><span class="pbjt-count"><?= count($tenants) ?> WP</span></small></h6></div>
            <div class="card-body">
                <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>WP</th><th>Nama</th><th>Jenis</th><th>Kecamatan</th><th>Database</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$tenants): ?><tr><td colspan="6" class="pbjt-empty">Belum ada tenant.</td></tr><?php endif; ?>
                    <?php foreach ($tenants as $t): ?>
                        <tr>
                            <td><strong><?= ta_h($t->wp_id) ?></strong></td>
                            <td><?= ta_h($t->nama) ?></td>
                            <td style="text-transform:capitalize"><?= ta_h($t->jenis_usaha) ?></td>
                            <td><?= ta_h($t->kecamatan) ?></td>
                            <td><span class="pbjt-dbname"><?= ta_h($t->db_name) ?></span></td>
                            <td><?= $t->status ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-secondary">Nonaktif</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>
