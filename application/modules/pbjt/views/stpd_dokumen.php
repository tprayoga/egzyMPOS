<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$rp = function ($n) { return 'Rp ' . number_format((int) $n, 0, ',', '.'); };
$tgl = function ($d) {
    $b = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $t = strtotime($d);
    return $t ? date('j', $t) . ' ' . $b[(int) date('n', $t)] . ' ' . date('Y', $t) : '-';
};
$masaLabel = $tgl($s['masa'] . '-01');
$masaLabel = substr($masaLabel, strpos($masaLabel, ' ') + 1);
$draft = empty($cfg['terverifikasi']);
$persen = rtrim(rtrim(number_format((float) $cfg['bunga_per_bulan'] * 100, 2, ',', '.'), '0'), ',');
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($s['nomor']) ?> — STPD</title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; color: #111; background: #eee; margin: 0; }
    .page { position: relative; background: #fff; max-width: 780px; margin: 24px auto; padding: 40px 48px; box-sizing: border-box; overflow: hidden; }
    .wm { position: absolute; top: 38%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 150px; font-weight: 700;
          color: rgba(200, 0, 0, .13); letter-spacing: 12px; pointer-events: none; white-space: nowrap; }
    .kop { text-align: center; border-bottom: 3px double #111; padding-bottom: 10px; margin-bottom: 18px; }
    .kop h1 { font-size: 18px; margin: 0; } .kop h2 { font-size: 15px; margin: 4px 0 0; font-weight: 600; }
    .judul { text-align: center; margin: 10px 0 18px; } .judul b { font-size: 16px; text-decoration: underline; } .judul div { font-size: 13px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .id td { padding: 3px 4px; vertical-align: top; } .id td:first-child { width: 190px; }
    .rinci { margin-top: 16px; } .rinci th, .rinci td { border: 1px solid #333; padding: 6px 8px; }
    .rinci th { background: #f2f2f2; text-align: left; } .num { text-align: right; white-space: nowrap; }
    .total td { font-weight: 700; background: #f7f7f7; }
    .catatan { font-size: 12px; margin-top: 16px; line-height: 1.5; }
    .draft-note { border: 1px solid #c00; color: #900; padding: 8px 10px; font-size: 12px; margin-bottom: 14px; }
    .ttd { margin-top: 36px; width: 280px; margin-left: auto; text-align: center; font-size: 13px; }
    .ttd .space { height: 70px; }
    .toolbar { max-width: 780px; margin: 16px auto 0; text-align: right; }
    .toolbar button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
    @media print { body { background: #fff; } .page { margin: 0; max-width: none; } .toolbar { display: none; } }
    @media (max-width: 600px) { .page { padding: 24px 16px; } .id td:first-child { width: 130px; } .wm { font-size: 80px; } }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Cetak</button></div>
<div class="page">
    <?php if ($draft): ?><div class="wm">DRAFT</div><?php endif; ?>
    <div class="kop">
        <h1>PEMERINTAH DAERAH</h1>
        <h2>BADAN PENDAPATAN DAERAH</h2>
    </div>
    <?php if ($draft): ?>
    <div class="draft-note"><b>DRAFT — bukan dokumen resmi.</b> Parameter sanksi <?= $h($cfg['catatan']) ?>.</div>
    <?php endif; ?>
    <div class="judul">
        <b>SURAT TAGIHAN PAJAK DAERAH (STPD)</b>
        <div>Pajak Barang dan Jasa Tertentu (PBJT) &middot; Nomor: <?= $h($s['nomor']) ?></div>
    </div>

    <table class="id">
        <tr><td>Nama Wajib Pajak</td><td>: <?= $h($s['nama'] ?: '-') ?></td></tr>
        <tr><td>NPWPD / ID WP</td><td>: <?= $h($s['wp_id']) ?></td></tr>
        <tr><td>Jenis usaha</td><td>: <?= $h($s['jenis_usaha'] === 'resto' ? 'Jasa makanan dan/atau minuman' : 'Jasa perhotelan') ?></td></tr>
        <tr><td>Alamat</td><td>: <?= $h(!empty($alamat['address']) ? $alamat['address'] : ('Kec. ' . $s['kecamatan'])) ?></td></tr>
        <tr><td>Masa pajak</td><td>: <?= $h($masaLabel) ?></td></tr>
        <tr><td>Jatuh tempo pembayaran</td><td>: <?= $h($tgl($s['jatuh_tempo'])) ?></td></tr>
        <tr><td><?= $s['jenis_acuan'] === 'bayar' ? 'Tanggal pembayaran' : 'Tanggal terbit (belum dibayar)' ?></td><td>: <?= $h($tgl($s['tanggal_acuan'])) ?></td></tr>
        <tr><td>Tanggal terbit STPD</td><td>: <?= $h($tgl($s['diterbitkan_pada'])) ?></td></tr>
    </table>

    <table class="rinci">
        <tr><th>Uraian</th><th class="num">Jumlah</th></tr>
        <tr><td>Pokok pajak yang terlambat dibayar (dasar bunga)</td><td class="num"><?= $rp($s['dasar_bunga']) ?></td></tr>
        <tr><td>Pokok pajak kurang bayar</td><td class="num"><?= $rp($s['pokok_kurang_bayar']) ?></td></tr>
        <tr><td>Jumlah bulan keterlambatan<?= !empty($cfg['bulan_parsial_dihitung_penuh']) ? ' (bagian bulan dihitung satu bulan)' : '' ?>, maks. <?= (int) $cfg['maks_bulan'] ?> bulan</td>
            <td class="num"><?= (int) $s['jumlah_bulan'] ?> bulan</td></tr>
        <tr><td>Bunga keterlambatan <?= $h($persen) ?>% &times; <?= (int) $s['jumlah_bulan'] ?> bulan &times; <?= $rp($s['dasar_bunga']) ?></td><td class="num"><?= $rp($s['bunga']) ?></td></tr>
        <tr class="total"><td>Jumlah yang harus dibayar (pokok kurang bayar + bunga)</td><td class="num"><?= $rp($s['total']) ?></td></tr>
    </table>

    <div class="catatan">
        Bunga dihitung dari jatuh tempo sampai <?= $s['jenis_acuan'] === 'bayar' ? 'tanggal pembayaran' : 'tanggal terbit STPD' ?>.
        <?php if ($s['jenis_acuan'] === 'terbit'): ?>Pajak yang belum dibayar tetap dikenakan bunga sampai dilunasi.<?php endif; ?>
    </div>

    <div class="ttd">
        <div><?= $h($tgl($s['diterbitkan_pada'])) ?></div>
        <div>Kepala Badan Pendapatan Daerah</div>
        <div class="space"></div>
        <div>(&nbsp;................................................&nbsp;)</div>
    </div>
</div>
</body>
</html>
