<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/* Konten dashboard PBJT — dirender di dalam template/layout (sidebar + navbar aplikasi). */
if (!function_exists('pbjt_rp')) {
    function pbjt_rp($n){ return 'Rp ' . number_format((int)$n,0,',','.'); }
    function pbjt_rpk($n){ $n=(int)$n; if($n>=1e9)return 'Rp '.number_format($n/1e9,1,',','.').' M'; if($n>=1e6)return 'Rp '.number_format($n/1e6,1,',','.').' jt'; return pbjt_rp($n); }
    function pbjt_h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
    function pbjt_icon($p){ return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'.$p.'</svg>'; }
}
$isBapenda = $ctx['role'] === 'bapenda';
$maxKec = 1; foreach ($perKec as $r) $maxKec = max($maxKec, (int)$r['p']);
$base = rtrim(base_url(), '/');
$ic = [
  'money'   => pbjt_icon('<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.4"/><path d="M6 12h.01M18 12h.01"/>'),
  'building'=> pbjt_icon('<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 21V7M15 21V7M8 7h8"/>'),
  'alert'   => pbjt_icon('<path d="M12 3l9 16H3z"/><path d="M12 10v4M12 17h.01"/>'),
  'layers'  => pbjt_icon('<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/>'),
  'check'   => pbjt_icon('<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>'),
  'cal'     => pbjt_icon('<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>'),
  'refresh' => pbjt_icon('<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>'),
  'users'   => pbjt_icon('<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/>'),
  'inbox'   => pbjt_icon('<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13L22 12v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>'),
];
?>
<div class="pbjt-head">
    <div>
        <div class="eyebrow"><span class="dot"></span>Pengawasan PBJT &middot; Masa <?= pbjt_h($masa) ?></div>
        <h2><?= $isBapenda ? 'Dashboard Pengawasan Pajak' : 'Status Pajak Usaha Anda' ?>
            <span class="pbjt-role"><?= $isBapenda ? 'Bapenda' : 'WP '.pbjt_h($ctx['wp_id']) ?></span></h2>
        <p><?= $isBapenda ? 'Rekonsiliasi omzet setiap WP terhadap settlement QRIS/EDC 3 bank.' : 'Perbandingan omzet usaha Anda terhadap data transaksi non-tunai.' ?></p>
    </div>
    <form class="pbjt-actions" method="get" action="<?= $base ?>/pbjt">
        <select class="form-control" name="masa" onchange="this.form.submit()" aria-label="Masa pajak">
            <?php foreach ($masaList as $mm): ?>
                <option value="<?= pbjt_h($mm) ?>" <?= $mm===$masa?'selected':'' ?>>Masa <?= pbjt_h($mm) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($isBapenda): ?>
            <a class="btn btn-primary" href="<?= $base ?>/pbjt/reconcile?masa=<?= pbjt_h($masa) ?>"><?= $ic['refresh'] ?>Jalankan Rekonsiliasi</a>
            <a class="btn btn-light" href="<?= $base ?>/pbjt/daftar"><?= $ic['users'] ?>Kelola Tenant</a>
        <?php endif; ?>
    </form>
</div>

<div class="pbjt-kpis">
    <div class="pbjt-kpi">
        <div class="pbjt-ic teal"><?= $ic['money'] ?></div>
        <div>
            <div class="lbl"><?= $isBapenda ? 'Total potensi PBJT' : 'Potensi kurang bayar' ?></div>
            <div class="val"><?= pbjt_rpk($kpi['total_potensi']) ?></div>
            <div class="hint"><?= pbjt_rp($kpi['total_potensi']) ?></div>
        </div>
    </div>
    <div class="pbjt-kpi">
        <div class="pbjt-ic green"><?= $ic['building'] ?></div>
        <div>
            <div class="lbl"><?= $isBapenda ? 'WP terindikasi' : 'Temuan usaha Anda' ?></div>
            <div class="val"><?= (int)$kpi['jml_temuan'] ?><?php if ($isBapenda): ?> <small>/ <?= (int)$kpi['total_wp'] ?> WP</small><?php endif; ?></div>
            <div class="hint"><?= $isBapenda ? 'dari WP terpantau' : 'pada masa ini' ?></div>
        </div>
    </div>
    <?php if ($isBapenda): ?>
    <div class="pbjt-kpi">
        <div class="pbjt-ic amber"><?= $ic['alert'] ?></div>
        <div><div class="lbl">MID tak terpetakan</div><div class="val"><?= (int)$kpi['unmapped_n'] ?></div><div class="hint">perlu penelusuran</div></div>
    </div>
    <div class="pbjt-kpi">
        <div class="pbjt-ic blue"><?= $ic['layers'] ?></div>
        <div><div class="lbl">Nilai tak terpetakan</div><div class="val"><?= pbjt_rpk($kpi['unmapped_rp']) ?></div><div class="hint">settlement tanpa WP</div></div>
    </div>
    <?php else: ?>
    <div class="pbjt-kpi">
        <div class="pbjt-ic <?= $kpi['jml_temuan']>0 ? 'amber' : 'green' ?>"><?= $kpi['jml_temuan']>0 ? $ic['alert'] : $ic['check'] ?></div>
        <div><div class="lbl">Status</div><div class="val" style="font-size:19px"><?= $kpi['jml_temuan']>0 ? 'Perlu klarifikasi' : 'Sesuai' ?></div></div>
    </div>
    <div class="pbjt-kpi">
        <div class="pbjt-ic blue"><?= $ic['cal'] ?></div>
        <div><div class="lbl">Masa pajak</div><div class="val" style="font-size:19px"><?= pbjt_h($masa) ?></div></div>
    </div>
    <?php endif; ?>
</div>

<?php if ($isBapenda): ?>
<div class="pbjt-grid2">
    <div class="card">
        <div class="card-header"><h6>Potensi per Kecamatan</h6></div>
        <div class="card-body">
            <?php if (!$perKec): ?><div class="pbjt-empty"><?= $ic['inbox'] ?>Belum ada temuan.</div><?php endif; ?>
            <?php foreach ($perKec as $r): ?>
                <div class="pbjt-bar">
                    <div class="nm"><?= pbjt_h($r['kecamatan']) ?></div>
                    <div class="track"><div class="fill" style="width:<?= max(4, round($r['p']/$maxKec*100)) ?>%"></div></div>
                    <div class="amt"><?= pbjt_rpk($r['p']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h6>Ringkasan Masa <?= pbjt_h($masa) ?></h6></div>
        <div class="card-body">
            <p class="pbjt-summary"><strong><?= (int)$kpi['jml_temuan'] ?> dari <?= (int)$kpi['total_wp'] ?> WP</strong> terindikasi melaporkan omzet di bawah transaksi non-tunainya, dengan estimasi potensi <strong><?= pbjt_rp($kpi['total_potensi']) ?></strong>.</p>
            <ul class="pbjt-stat-list">
                <li><span>WP terpantau</span><b><?= (int)$kpi['total_wp'] ?></b></li>
                <li><span>WP terindikasi</span><b><?= (int)$kpi['jml_temuan'] ?></b></li>
                <li><span>MID tak terpetakan</span><b><?= (int)$kpi['unmapped_n'] ?> &middot; <?= pbjt_rp($kpi['unmapped_rp']) ?></b></li>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$csrf = '<input type="hidden" name="' . $this->security->get_csrf_token_name() . '" value="' . $this->security->get_csrf_hash() . '">';
$tagStatus = function ($r) {
    if ($r['status'] === 'lunas') return ['badge-success', 'Lunas'];
    if (!$r['tagihan_id']) return ['badge-secondary', 'Belum terbit'];
    if ($r['va_status'] === 'pending' && $r['va_expired_at'] && strtotime($r['va_expired_at']) < time()) return ['badge-danger', 'VA kedaluwarsa'];
    if ($r['va_status'] === 'pending') return ['badge-warning', 'Menunggu bayar'];
    return ['badge-danger', 'VA gagal terbit'];
};
$perluTerbit = 0;
foreach ($tagihan as $r) if ((int)$r['pajak_terutang'] > 0 && $r['status'] !== 'lunas' && !($r['va_status'] === 'pending' && (int)$r['pajak'] === (int)$r['pajak_terutang'])) $perluTerbit++;
?>
<?php if ($isBapenda): ?>
<div class="card">
    <div class="card-header">
        <h6>Tagihan PBJT Masa <?= pbjt_h($masa) ?> &middot; VA BNI
            <?php if ($masaSelesai && $perluTerbit): ?>
            <form method="post" action="<?= $base ?>/pbjt/terbit_va" class="float-right" style="margin:0" onsubmit="return confirm('Terbitkan VA untuk <?= $perluTerbit ?> WP?')">
                <?= $csrf ?><input type="hidden" name="masa" value="<?= pbjt_h($masa) ?>">
                <button class="btn btn-primary btn-sm">Terbitkan VA (<?= $perluTerbit ?> WP)</button>
            </form>
            <?php endif; ?>
        </h6>
    </div>
    <div class="card-body">
        <p class="text-muted pbjt-note">Dasar tagihan = pajak terlapor/tercatat masa ini. Potensi dari temuan rekonsiliasi tidak ditagihkan otomatis karena memerlukan pemeriksaan dan penetapan (SKPDKB).
            <?php if (!$masaSelesai): ?><br><strong>Masa ini belum berakhir; tagihan dapat diterbitkan setelah masa selesai.</strong><?php endif; ?></p>
        <div class="table-responsive">
        <table class="table table-hover pbjt-table">
            <thead><tr><th>Wajib Pajak</th><th class="num">Pajak Terutang</th><th>Status</th><th>Nomor VA</th><th>Jatuh Tempo</th><th></th></tr></thead>
            <tbody>
            <?php if (!$tagihan): ?><tr><td colspan="6" class="pbjt-empty"><?= $ic['inbox'] ?>Belum ada data tagihan.</td></tr><?php endif; ?>
            <?php foreach ($tagihan as $r): list($cls, $lbl) = $tagStatus($r);
                $berubah = $r['tagihan_id'] && $r['status'] !== 'lunas' && (int)$r['pajak'] !== (int)$r['pajak_terutang']; ?>
                <tr>
                    <td><div class="wp"><?= pbjt_h($r['nama']) ?></div><div class="wp-id"><?= pbjt_h($r['wp_id']) ?></div></td>
                    <td class="num"><strong><?= pbjt_rp($r['status'] === 'lunas' ? $r['dibayar'] : $r['pajak_terutang']) ?></strong>
                        <?php if ($berubah): ?><div class="wp-id">VA saat ini <?= pbjt_rp($r['pajak']) ?> &mdash; perlu terbit ulang</div><?php endif; ?></td>
                    <td><span class="badge <?= $cls ?>"><?= $lbl ?></span><?php if ($r['lunas_pada']): ?><div class="wp-id"><?= pbjt_h($r['lunas_pada']) ?></div><?php endif; ?></td>
                    <td><?= $r['va_no'] ? '<span class="pbjt-dbname">' . pbjt_h($r['va_no']) . '</span>' : '&ndash;' ?></td>
                    <td><?= $r['jatuh_tempo'] ? pbjt_h($r['jatuh_tempo']) : '&ndash;' ?>
                        <?php if ($r['jatuh_tempo'] && $r['status'] !== 'lunas' && $r['jatuh_tempo'] < date('Y-m-d')): ?><div class="wp-id pbjt-late">terlambat</div><?php endif; ?></td>
                    <td class="pbjt-row-actions">
                    <?php if ($r['status'] !== 'lunas' && (int)$r['pajak_terutang'] > 0 && $masaSelesai): ?>
                        <form method="post" action="<?= $base ?>/pbjt/terbit_va"><?= $csrf ?>
                            <input type="hidden" name="masa" value="<?= pbjt_h($masa) ?>"><input type="hidden" name="wp_id" value="<?= pbjt_h($r['wp_id']) ?>">
                            <button class="btn btn-light btn-sm"><?= $r['tagihan_id'] ? 'Terbit ulang' : 'Terbitkan VA' ?></button></form>
                    <?php endif; ?>
                    <?php if ($r['status'] !== 'lunas' && $r['va_status'] === 'pending'): ?>
                        <form method="post" action="<?= $base ?>/pbjt/cek_va"><?= $csrf ?>
                            <input type="hidden" name="masa" value="<?= pbjt_h($masa) ?>"><input type="hidden" name="tagihan_id" value="<?= (int)$r['tagihan_id'] ?>">
                            <button class="btn btn-light btn-sm">Cek status</button></form>
                    <?php endif; ?>
                    <?php $sp = $r['tagihan_id'] && isset($stpd[(int)$r['tagihan_id']]) ? $stpd[(int)$r['tagihan_id']] : null; ?>
                    <?php if ($r['tagihan_id'] && Pbjt_stpd::terlambat($r)): ?>
                        <form method="post" action="<?= $base ?>/pbjt/terbit_stpd" onsubmit="return confirm('Terbitkan STPD bunga keterlambatan untuk <?= pbjt_h($r['wp_id']) ?>?')"><?= $csrf ?>
                            <input type="hidden" name="masa" value="<?= pbjt_h($masa) ?>"><input type="hidden" name="tagihan_id" value="<?= (int)$r['tagihan_id'] ?>">
                            <button class="btn btn-light btn-sm"><?= $sp ? 'Terbitkan ulang STPD' : 'Terbitkan STPD' ?></button></form>
                    <?php endif; ?>
                    <?php if ($sp): ?><a class="btn btn-light btn-sm" target="_blank" href="<?= $base ?>/pbjt/stpd/<?= (int)$sp['id'] ?>">Lihat STPD</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php else: $r = $tagihan ? $tagihan[0] : null; ?>
<div class="card">
    <div class="card-header"><h6>Tagihan PBJT Masa <?= pbjt_h($masa) ?></h6></div>
    <div class="card-body">
    <?php if (!$r || !$r['tagihan_id']): ?>
        <div class="pbjt-empty"><?= $ic['cal'] ?>Tagihan masa ini belum diterbitkan Bapenda.</div>
    <?php else: list($cls, $lbl) = $tagStatus($r); ?>
        <div class="pbjt-bill">
            <div><div class="lbl">Jumlah</div><div class="big"><?= pbjt_rp($r['status'] === 'lunas' ? $r['dibayar'] : $r['pajak']) ?></div></div>
            <div><div class="lbl">Virtual Account BNI</div><div class="big mono"><?= pbjt_h($r['va_no'] ?: '-') ?></div></div>
            <div><div class="lbl">Jatuh tempo</div><div class="big"><?= pbjt_h($r['jatuh_tempo']) ?></div>
                <?php if ($r['status'] !== 'lunas' && $r['jatuh_tempo'] < date('Y-m-d')): ?><div class="pbjt-late">Terlambat &mdash; segera bayar sebelum <?= pbjt_h(substr((string)$r['va_expired_at'], 0, 10)) ?></div><?php endif; ?></div>
            <div><div class="lbl">Status</div><div><span class="badge <?= $cls ?>"><?= $lbl ?></span></div></div>
        </div>
        <?php if ($r['status'] !== 'lunas' && $r['va_status'] === 'pending'): ?>
        <p class="pbjt-note">Bayar melalui ATM, mobile banking, atau teller dengan memilih transfer ke Virtual Account BNI, lalu masukkan nomor di atas. Status diperbarui otomatis setelah bank mengonfirmasi.</p>
        <form method="post" action="<?= $base ?>/pbjt/cek_va"><?= $csrf ?>
            <input type="hidden" name="masa" value="<?= pbjt_h($masa) ?>"><input type="hidden" name="tagihan_id" value="<?= (int)$r['tagihan_id'] ?>">
            <button class="btn btn-light btn-sm">Cek status pembayaran</button></form>
        <?php endif; ?>
        <?php if (isset($stpd[(int)$r['tagihan_id']])): $sp = $stpd[(int)$r['tagihan_id']]; ?>
        <p class="pbjt-note pbjt-late">Bapenda menerbitkan STPD <?= pbjt_h($sp['nomor']) ?> atas keterlambatan pembayaran: <?= pbjt_rp($sp['total']) ?>.
            <a target="_blank" href="<?= $base ?>/pbjt/stpd/<?= (int)$sp['id'] ?>">Lihat STPD</a></p>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h6><?= $isBapenda ? 'Daftar Temuan' : 'Temuan atas Usaha Anda' ?>
            <small class="float-right"><span class="badge badge-secondary"><?= count($temuan) ?> temuan &middot; urut potensi</span></small></h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
        <table class="table table-hover pbjt-table">
            <thead><tr>
                <?php if ($isBapenda): ?><th>Wajib Pajak</th><th>Jenis</th><th>Kecamatan</th><?php endif; ?>
                <th class="num">Omzet Lapor</th><th class="num">QRIS Agregat</th>
                <th class="num">Potensi PBJT</th><th>Risiko</th><th>Sinyal</th><th>Aturan</th>
            </tr></thead>
            <tbody>
            <?php if (!$temuan): ?>
                <tr><td colspan="9" class="pbjt-empty"><?= $ic['check'] ?><?= $isBapenda ? 'Belum ada temuan untuk masa ini.' : 'Tidak ada temuan — pelaporan Anda sesuai data.' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($temuan as $t):
                $s = (int)$t['skor_risiko'];
                $cls = $s>=80 ? 'badge-danger' : ($s>=50 ? 'badge-warning' : 'badge-success');
                $lbl = $s>=80 ? 'Tinggi' : ($s>=50 ? 'Sedang' : 'Rendah'); ?>
                <tr>
                    <?php if ($isBapenda): ?>
                    <td><div class="wp"><?= pbjt_h($t['nama']) ?></div><div class="wp-id"><?= pbjt_h($t['wp_id']) ?></div></td>
                    <td style="text-transform:capitalize"><?= pbjt_h($t['jenis_usaha']) ?></td>
                    <td><?= pbjt_h($t['kecamatan']) ?></td>
                    <?php endif; ?>
                    <td class="num"><?= pbjt_rp($t['omzet_terlapor']) ?></td>
                    <td class="num"><?= pbjt_rp($t['qris_teragregasi']) ?></td>
                    <td class="num"><strong><?= pbjt_rp($t['potensi_rp']) ?></strong></td>
                    <td><span class="badge <?= $cls ?> pbjt-risk"><span class="d"></span><?= $lbl ?> &middot; <?= $s ?></span></td>
                    <td><span class="badge badge-secondary"><?= pbjt_h($t['cakupan_sinyal']) ?></span></td>
                    <td class="rule"><?= pbjt_h($t['aturan_terpicu']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php if ($isBapenda && $unmapped): ?>
<div class="card">
    <div class="card-header"><h6>Antrean MID Tak Terpetakan <small class="float-right"><span class="badge badge-warning"><?= count($unmapped) ?> MID</span></small></h6></div>
    <div class="card-body">
        <p class="text-muted" style="font-size:13px;margin-top:0">MID pada settlement bank yang tidak ada di peta NPWPD&harr;MID &mdash; indikasi outlet baru/tersembunyi atau WP belum terdaftar.</p>
        <?php foreach ($unmapped as $u): ?>
            <div class="pbjt-mid">
                <div><span class="code"><?= pbjt_h($u['mid']) ?></span> <span class="bank">&middot; <?= pbjt_h($u['bank']) ?></span></div>
                <div class="amt"><?= pbjt_rp($u['nilai_settlement']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
