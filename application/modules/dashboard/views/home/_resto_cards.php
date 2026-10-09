<?php
// Kartu ringkasan restoran (dipakai dashboard restoran & dashboard hotel yang punya restoran).
$rp = function ($v) { return 'Rp ' . number_format((float) $v, 0, ',', '.'); };
$cards = array(
    array('warning', 'today', 'Order hari ini', number_format($sum->today->n, 0, ',', '.'), 'Omzet selesai hari ini ' . $rp($sum->today->v)),
    array('success', 'attach_money', 'Omzet order selesai', $rp($sum->all->v), number_format($sum->all->n, 0, ',', '.') . ' order selesai'),
    array('info', 'pending_actions', 'Order berjalan', number_format($sum->running->n, 0, ',', '.'), $rp($sum->running->v) . ' belum selesai · ' . number_format($sum->cancel->n, 0, ',', '.') . ' batal'),
    array('danger', 'receipt_long', 'PBJT restoran bulan ini', $rp($sum->month->p), 'DPP ' . $rp($sum->month->v) . ' (' . number_format($sum->month->n, 0, ',', '.') . ' order tidak batal)'),
);
?>
<div class="row">
    <?php foreach ($cards as $c) { ?>
    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6">
        <div class="card card-stats statistic-box mb-4">
            <div class="card-header card-header-<?php echo $c[0]; ?> card-header-icon position-relative border-0 text-right px-3 py-0">
                <div class="card-icon d-flex align-items-center justify-content-center">
                    <i class="material-icons"><?php echo $c[1]; ?></i>
                </div>
                <p class="card-category text-uppercase fs-10 font-weight-bold text-muted"><?php echo html_escape($c[2]); ?></p>
                <h3 class="card-title fs-18 font-weight-bold"><?php echo html_escape($c[3]); ?></h3>
            </div>
            <div class="card-footer p-3">
                <div class="stats fs-12 text-muted"><?php echo html_escape($c[4]); ?></div>
            </div>
        </div>
    </div>
    <?php } ?>
</div>
