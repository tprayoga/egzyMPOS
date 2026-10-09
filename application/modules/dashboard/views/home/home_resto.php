<?php
$rp = function ($v) { return 'Rp ' . number_format((float) $v, 0, ',', '.'); };
$statusLabel = array(1 => 'Pending', 2 => 'Diproses', 3 => 'Siap', 4 => 'Selesai', 5 => 'Batal');
$statusClass = array(1 => 'warning', 2 => 'info', 3 => 'primary', 4 => 'success', 5 => 'danger');
$max = 0;
foreach ($monthly as $m) $max = max($max, (float) $m->v);
?>
<?php $this->load->view('dashboard/home/_resto_cards', array('sum' => $sum)); ?>

<div class="row">
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header"><h6 class="fs-17 font-weight-600 mb-0">Omzet order selesai per bulan</h6></div>
            <div class="card-body">
                <?php if (empty($monthly)) { ?>
                <p class="text-muted mb-0">Belum ada order selesai.</p>
                <?php } ?>
                <?php foreach ($monthly as $m) { $w = $max > 0 ? round($m->v / $max * 100) : 0; ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between fs-13">
                        <span class="font-weight-bold"><?php echo date('M Y', strtotime($m->ym . '-01')); ?></span>
                        <span><?php echo $rp($m->v); ?> · <?php echo number_format($m->n, 0, ',', '.'); ?> order</span>
                    </div>
                    <div class="progress" style="height:8px"><div class="progress-bar bg-success" style="width:<?php echo $w; ?>%"></div></div>
                </div>
                <?php } ?>
                <p class="fs-12 text-muted mb-0">PBJT dihitung engine PBJT dengan tarif <?php echo $tarif_pbjt * 100; ?>% (ketetapan Pemda) per transaksi, sama dengan menu Pengawasan PBJT. Informasi, bukan ketetapan pajak.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="fs-17 font-weight-600 mb-0">Order terbaru</h6>
                <a href="<?php echo base_url('ordermanage/order/orderlist'); ?>" class="btn btn-sm btn-primary">Order list</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Invoice</th><th>Tanggal</th><th>Meja</th><th class="text-right">Total</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (empty($latest)) { ?>
                            <tr><td colspan="5" class="text-center text-muted">Belum ada order.</td></tr>
                        <?php } ?>
                        <?php foreach ($latest as $o) { ?>
                            <tr>
                                <td><?php echo html_escape($o->saleinvoice); ?></td>
                                <td><?php echo html_escape(date('d/m/Y', strtotime($o->order_date)) . ' ' . substr((string) $o->order_time, 0, 5)); ?></td>
                                <td><?php echo html_escape($o->tablename ?: '-'); ?></td>
                                <td class="text-right"><?php echo $rp($o->totalamount); ?></td>
                                <td><span class="badge badge-<?php echo isset($statusClass[$o->order_status]) ? $statusClass[$o->order_status] : 'secondary'; ?>"><?php echo isset($statusLabel[$o->order_status]) ? $statusLabel[$o->order_status] : html_escape($o->order_status); ?></span></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
