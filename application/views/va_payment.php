<?php
defined('BASEPATH') or exit('No direct script access allowed');
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES); };
$cur = !empty($this->storecurrency->curr_icon) ? $this->storecurrency->curr_icon : 'Rp';
$paid = $va && $va['status'] === 'paid';
$pending = $va && $va['status'] === 'pending' && !$error;
?>
<style>
.va-wrap { max-width: 640px; margin: 0 auto; }
.va-card { background: #fff; border: 1px solid #e6e9ef; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, .06); padding: 28px; }
.va-card h2 { font-size: 22px; font-weight: 800; margin: 0 0 4px; }
.va-sub { color: #64748b; font-size: 14px; margin-bottom: 22px; }
.va-row { display: flex; justify-content: space-between; gap: 12px; padding: 12px 0; border-top: 1px solid #eef2f6; font-size: 14px; }
.va-row span { color: #64748b; }
.va-row b { text-align: right; }
.va-num { display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #f1f5f9; border-radius: 12px; padding: 16px 18px; margin: 4px 0 18px; }
.va-num .n { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 24px; font-weight: 800; letter-spacing: .06em; word-break: break-all; }
.va-amount { font-size: 28px; font-weight: 800; margin: 0 0 18px; }
.va-steps { padding-left: 18px; color: #334155; font-size: 14px; line-height: 1.8; margin: 18px 0 0; }
.va-state { display: flex; align-items: center; gap: 10px; margin-top: 18px; font-size: 14px; color: #64748b; }
.va-dot { width: 10px; height: 10px; border-radius: 50%; background: #f59e0b; animation: va-pulse 1.4s infinite; }
@keyframes va-pulse { 50% { opacity: .3; } }
.va-ok { text-align: center; }
.va-ok .ic { width: 64px; height: 64px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: grid; place-items: center; margin: 0 auto 14px; font-size: 32px; font-weight: 800; }
@media (max-width: 575px) { .va-card { padding: 20px; } .va-num .n { font-size: 19px; } }
</style>
<div class="section">
  <div class="container">
    <div class="va-wrap">
      <div class="va-card" id="va-card">
      <?php if ($paid): ?>
        <div class="va-ok">
          <div class="ic">&#10003;</div>
          <h2>Pembayaran diterima</h2>
          <p class="va-sub">Booking <b><?= $h($booking->booking_number) ?></b> sudah dibayar melalui Virtual Account BNI. Terima kasih.</p>
          <a class="btn btn-primary" href="<?= base_url('hotel/report') ?>">Lihat booking saya</a>
        </div>
      <?php elseif ($error): ?>
        <h2>Pembayaran belum dapat diproses</h2>
        <p class="va-sub"><?= $h($error) ?></p>
        <div class="va-row"><span>Nomor booking</span><b><?= $h($booking->booking_number) ?></b></div>
        <a class="btn btn-primary mt-3" href="<?= base_url('hotel/va/' . $booking->booking_number) ?>">Coba lagi</a>
      <?php else: ?>
        <h2>Selesaikan pembayaran</h2>
        <p class="va-sub">Transfer tepat sesuai jumlah ke nomor Virtual Account BNI berikut.</p>
        <div class="va-amount"><?= $h($cur) ?> <?= number_format((float) $va['amount'], 0, ',', '.') ?></div>
        <div class="va-num">
          <span class="n" id="va-no"><?= $h(trim($va['va_no'])) ?></span>
          <button type="button" class="btn btn-light btn-sm" id="va-copy">Salin</button>
        </div>
        <div class="va-row"><span>Bank</span><b>BNI</b></div>
        <div class="va-row"><span>Atas nama</span><b><?= $h($va['va_name']) ?></b></div>
        <div class="va-row"><span>Nomor booking</span><b><?= $h($booking->booking_number) ?></b></div>
        <div class="va-row"><span>Bayar sebelum</span><b><?= $h(date('d M Y H:i', strtotime($va['expired_at']))) ?></b></div>
        <ol class="va-steps">
          <li>Buka BNI Mobile/ATM BNI, pilih <b>Transfer &rarr; Virtual Account Billing</b>.</li>
          <li>Dari bank lain, pilih transfer antarbank ke <b>BNI</b> lalu masukkan nomor VA.</li>
          <li>Pastikan nama dan jumlah sesuai, lalu konfirmasi.</li>
        </ol>
        <div class="va-state" id="va-state"><span class="va-dot"></span>Menunggu konfirmasi pembayaran dari bank&hellip;</div>
      <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php if ($pending): ?>
<script>
(function () {
  var copy = document.getElementById('va-copy');
  copy.addEventListener('click', function () {
    var n = document.getElementById('va-no').textContent;
    if (navigator.clipboard) navigator.clipboard.writeText(n).then(function () { copy.textContent = 'Tersalin'; });
  });
  var url = <?= json_encode(base_url('hotel/va_status/' . $booking->booking_number)) ?>;
  var state = document.getElementById('va-state');
  function poll() {
    fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.status === 'paid') { window.location.reload(); return; }
      if (d.status === 'expired') { state.innerHTML = 'Nomor VA sudah kedaluwarsa. <a href="' + <?= json_encode(base_url('hotel/va/' . $booking->booking_number)) ?> + '">Buat nomor baru</a>'; return; }
      setTimeout(poll, 10000);
    }).catch(function () { setTimeout(poll, 20000); });
  }
  setTimeout(poll, 10000);
})();
</script>
<?php endif; ?>
