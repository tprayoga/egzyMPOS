<?php
require_once __DIR__ . '/Snap_sign.php';
require_once __DIR__ . '/Snap_store.php';
require_once __DIR__ . '/Snap_client.php';

/**
 * SNAP BI — layanan VA generik yang dipakai alur PBJT maupun booking.
 *
 *   $svc->issue('bapenda', 'pbjt', null, 'TAG-2026-08-WP001', 'HOTEL MELATI', 1250000, '+7 days')
 *
 * Saat bank mengirim notifikasi lunas, baris registry ditandai `paid` lalu
 * diteruskan ke handler purpose (config `handlers`) lewat dispatch(). Handler
 * yang gagal TIDAK membatalkan jawaban ke bank; baris tetap `dispatched_at` NULL
 * dan dapat diulang (redispatchPending).
 */
class Snap_service
{
    private $cfg;
    private $store;
    private $transport;

    public function __construct(array $cfg, Snap_store $store, callable $transport = null)
    {
        $this->cfg = $cfg;
        $this->store = $store;
        $this->transport = $transport;
    }

    public function store()
    {
        return $this->store;
    }

    public function profile($name)
    {
        if (empty($this->cfg['profiles'][$name])) {
            throw new InvalidArgumentException("Profil SNAP '$name' tidak dikonfigurasi");
        }
        return $this->cfg['profiles'][$name];
    }

    public function client($name)
    {
        return new Snap_client($name, $this->profile($name), $this->store, $this->transport);
    }

    /**
     * Terbitkan VA baru di bank. Mengembalikan baris registry (status pending atau failed).
     * Bila ref yang sama masih punya VA pending yang belum kedaluwarsa, VA itu dipakai ulang.
     */
    public function issue($profileName, $purpose, $tenantDb, $ref, $name, $amount, $expires = '+1 day')
    {
        $p = $this->profile($profileName);
        if ((float) $amount <= 0) {
            throw new InvalidArgumentException('Nominal VA harus > 0');
        }
        $existing = $this->store->findByRef($purpose, $ref, $tenantDb);
        if ($existing && $existing['status'] === 'paid') {
            throw new RuntimeException("Tagihan $purpose/$ref sudah lunas");
        }
        if ($existing && $existing['status'] === 'pending') {
            $alive = !$existing['expired_at'] || strtotime($existing['expired_at']) > time();
            if ($alive && (float) $existing['amount'] === (float) $amount) {
                return $existing;
            }
            if ($alive) {
                // Nominal berubah: tutup VA lama di bank agar tidak ada dua VA aktif untuk satu tagihan.
                list($http, $res) = $this->client($existing['profile'])->deleteVa($existing);
                if ($http !== 200) {
                    throw new RuntimeException('Gagal menghapus VA lama: ' . (isset($res['responseMessage']) ? $res['responseMessage'] : "HTTP $http"));
                }
                $this->store->setStatus($existing['id'], 'deleted', json_encode($res, JSON_UNESCAPED_SLASHES));
            } else {
                $this->store->setStatus($existing['id'], 'expired');
            }
        }

        $row = $this->store->insertVa([
            'profile'            => $profileName,
            'purpose'            => $purpose,
            'tenant_db'          => $tenantDb,
            'ref'                => (string) $ref,
            'partner_service_id' => str_pad($p['partner_service_id'], 8, ' ', STR_PAD_LEFT),
            'va_name'            => mb_substr(trim($name), 0, 255),
            'amount'             => (float) $amount,
            'currency'           => 'IDR',
            'expired_at'         => $expires ? date('Y-m-d H:i:s', strtotime($expires)) : null,
            'trx_prefix'         => isset($p['trx_prefix']) ? $p['trx_prefix'] : 'EGZY',
        ], isset($p['customer_no_length']) ? (int) $p['customer_no_length'] : 8);

        list($http, $res) = $this->client($profileName)->createVa($row);
        $ok = $http === 200 && isset($res['responseCode']) && substr($res['responseCode'], 0, 3) === '200';
        $this->store->setStatus($row['id'], $ok ? 'pending' : 'failed', json_encode($res, JSON_UNESCAPED_SLASHES));
        return $this->store->getVa($row['id']);
    }

    /** Tanya status ke bank; bila bank menyatakan lunas, tandai & teruskan. */
    public function checkStatus($id)
    {
        $va = $this->store->getVa($id);
        if (!$va) throw new InvalidArgumentException("VA #$id tidak ada");
        list($http, $res) = $this->client($va['profile'])->inquiryStatus($va);
        $flag = isset($res['virtualAccountData']['paymentFlagStatus']) ? $res['virtualAccountData']['paymentFlagStatus'] : null;
        if ($http === 200 && $flag === '00' && $va['status'] === 'pending') {
            $paid = isset($res['virtualAccountData']['paidAmount']['value']) ? $res['virtualAccountData']['paidAmount']['value'] : $va['amount'];
            $reqId = isset($res['virtualAccountData']['paymentRequestId']) ? $res['virtualAccountData']['paymentRequestId'] : 'status-check';
            if ($this->store->markPaid($va['id'], $paid, $reqId)) {
                $this->dispatch($this->store->getVa($va['id']));
            }
        }
        return [$http, $res, $this->store->getVa($id)];
    }

    /** Teruskan VA lunas ke handler purpose-nya. Tidak melempar exception. */
    public function dispatch(array $va)
    {
        $h = isset($this->cfg['handlers'][$va['purpose']]) ? $this->cfg['handlers'][$va['purpose']] : null;
        if (!$h) {
            $this->store->markDispatched($va['id'], "Belum ada handler untuk purpose '{$va['purpose']}'");
            return false;
        }
        try {
            if (is_callable($h)) {
                call_user_func($h, $va);
            } else {
                require_once $h['file'];
                $obj = new $h['class']();
                $obj->onPaid($va);
            }
            $this->store->markDispatched($va['id']);
            return true;
        } catch (Throwable $e) {
            $this->store->markDispatched($va['id'], $e->getMessage());
            return false;
        }
    }

    public function redispatchPending()
    {
        $n = 0;
        foreach ($this->store->undispatched() as $va) {
            if ($this->dispatch($va)) $n++;
        }
        return $n;
    }
}
