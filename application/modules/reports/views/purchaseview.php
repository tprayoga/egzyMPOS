<link type="text/css" href="<?php echo MOD_URL . $module; ?>/assets/css/table.css">
<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="card">
    <div class="row">
        <div class="col-sm-12 col-md-12">
            <div class="panel panel-bd lobidrag">
                <div class="card-header">
                    <h4><?php echo display('stock_report') ?>

                    </h4>
                </div>
            </div>
        </div>
        <div class="card-body" id="printArea">
            <div class="table-responsive">
                <table id="exdatatable" class="table table-bordered table-striped table-hover">
                    <thead>
                        <tr>
                            <th><?php echo display('ingredient_name') ?></th>
                            <th class="text-right">Dibeli</th>
                            <th class="text-right">Diretur</th>
                            <th class="text-right">Rusak</th>
                            <th class="text-right">Terpakai</th>
                            <th class="text-right">Stok saat ini</th>
                            <th class="text-right">Nilai pembelian</th>
                        </tr>
                    </thead>
                    <tbody id="addinvoiceItem">
                        <?php
                        $grandpr = 0;
                        $fmt = function ($n) { return rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ','); };
                        foreach ($stockreport as $report) {
                            $grandpr += $report->nilai_beli;
                            $u = ' ' . html_escape($report->uom_short_code);
                        ?>
                        <tr>
                            <td><?php echo html_escape($report->product_name); ?></td>
                            <td class="text-right"><?php echo $fmt($report->dibeli) . $u; ?></td>
                            <td class="text-right"><?php echo $fmt($report->diretur) . $u; ?></td>
                            <td class="text-right"><?php echo $fmt($report->rusak) . $u; ?></td>
                            <td class="text-right"><?php echo $fmt($report->terpakai) . $u; ?></td>
                            <td class="text-right"><strong><?php echo $fmt($report->stok) . $u; ?></strong></td>
                            <td class="text-right"><?php echo 'Rp ' . number_format((float) $report->nilai_beli, 0, ',', '.'); ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" align="right"><b><?php echo display('grand_total') ?>:</b></td>
                            <td class="text-right"><b><?php echo 'Rp ' . number_format($grandpr, 0, ',', '.'); ?></b></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div>
</div>
</div>
</div>