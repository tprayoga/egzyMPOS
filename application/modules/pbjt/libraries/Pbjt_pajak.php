<?php
defined('BASEPATH') OR (defined('PBJT_CLI') OR exit('No direct script access allowed'));

/**
 * Engine PBJT per transaksi — SATU fungsi untuk kamar & restoran (keputusan 2026-10-09).
 *
 *   bruto = subtotal + service charge        (service charge & komponen non-PBJT masuk DPP)
 *   harga termasuk pajak : dpp = bruto / (1 + tarif); pbjt = bruto − dpp;  total = bruto
 *   harga belum termasuk : dpp = bruto;               pbjt = dpp × tarif;  total = dpp + pbjt
 * Dihitung di level total transaksi, dibulatkan SEKALI di akhir ke rupiah.
 * Tarif dari konfigurasi Pemda (config/pbjt.php), tidak dari Tax Management merchant.
 *
 * Flag "harga termasuk pajak": kamar per listing (roomdetails.price_includes_tax, default 1),
 * restoran per outlet (setting.resto_price_includes_tax, default 0).
 *
 * Kelas plain-PHP (mysqli) agar dipakai controller/hook CI, engine oversight, seed, dan test.
 */
class Pbjt_pajak
{
    /** Tarif PBJT dari konfigurasi Pemda. */
    public static function tarif()
    {
        static $t = null;
        if ($t === null) {
            $dir = defined('APPPATH') ? APPPATH : dirname(__DIR__, 3) . '/';
            $cfg = require $dir . 'config/pbjt.php';
            $t = (float) $cfg['tarif_pbjt'];
        }
        return $t;
    }

    /** Pola nama pajak daerah (sama dengan migrasi 010). Entri seperti ini ditolak di Tax Management. */
    const POLA_PAJAK_DAERAH = '/(^|[^a-z])(pb ?1|pbjt|pajak (daerah|hotel|restoran|resto|rumah makan|penginapan|barang dan jasa)|hotel tax|restaurant tax)/i';

    public static function adalahPajakDaerah($nama)
    {
        return (bool) preg_match(self::POLA_PAJAK_DAERAH, trim((string) $nama));
    }

    public static function hitung($subtotal, $serviceCharge, $tarif, $hargaTermasukPajak)
    {
        $tarif = (float) $tarif;
        $bruto = round((float) $subtotal + (float) $serviceCharge, 2);
        if ($hargaTermasukPajak) {
            $pbjt = round($bruto - $bruto / (1 + $tarif), 0);
            $dpp = round($bruto - $pbjt, 2);
            $total = $bruto;
        } else {
            $dpp = $bruto;
            $pbjt = round($dpp * $tarif, 0);
            $total = round($dpp + $pbjt, 2);
        }
        return ['bruto' => $bruto, 'dpp' => $dpp, 'pbjt' => $pbjt, 'total' => $total, 'tarif_snapshot' => $tarif];
    }

    /** Kamar: bruto = harga per malam × jumlah malam. */
    public static function kamar($hargaPerMalam, $jumlahMalam, $tarif, $hargaTermasukPajak = true)
    {
        $harga = round((float) $hargaPerMalam, 2);
        $malam = max(1, (int) $jumlahMalam);
        return ['harga_per_malam' => $harga, 'jumlah_malam' => $malam]
            + self::hitung($harga * $malam, 0, $tarif, $hargaTermasukPajak);
    }

    /** Jumlah malam = selisih tanggal kalender check-in/check-out, minimal 1. */
    public static function jumlahMalam($checkin, $checkout)
    {
        $a = strtotime(substr((string) $checkin, 0, 10));
        $b = strtotime(substr((string) $checkout, 0, 10));
        if (!$a || !$b) return 1;
        return max(1, (int) round(($b - $a) / 86400));
    }

    private static function q($db)
    {
        return '`' . str_replace('`', '', $db) . '`';
    }

    /**
     * Kamar: isi/perbarui kolom pajak booking dari total_price (= harga per malam) + tanggal.
     * Booking yang harga & jumlah malamnya tak berubah tidak disentuh (snapshot tarif & perlakuan).
     */
    public static function sinkronKamar(mysqli $db, $tenantDb, $tarif)
    {
        $t = self::q($tenantDb);
        $r = $db->query("SELECT b.bookedid, b.total_price, b.checkindate, b.checkoutdate, b.harga_per_malam, b.jumlah_malam,
                b.bruto, b.pbjt, b.tarif_snapshot, COALESCE(rd.price_includes_tax, 1) incl
            FROM $t.booked_info b
            LEFT JOIN $t.roomdetails rd ON rd.roomid = CAST(SUBSTRING_INDEX(b.roomid, ',', 1) AS UNSIGNED)");
        if (!$r) return 0;
        $st = $db->prepare("UPDATE $t.booked_info SET harga_per_malam=?, jumlah_malam=?, bruto=?, dpp=?, pbjt=?, tarif_snapshot=? WHERE bookedid=?");
        if (!$st) return 0; // DB tenant belum dimigrasi (009)
        $n = 0;
        while ($b = $r->fetch_assoc()) {
            $malam = self::jumlahMalam($b['checkindate'], $b['checkoutdate']);
            $tetap = $b['tarif_snapshot'] !== null && $b['bruto'] !== null && $b['pbjt'] !== null && (int) $b['jumlah_malam'] === $malam
                && round((float) $b['harga_per_malam'], 2) === round((float) $b['total_price'], 2);
            if ($tetap) continue;
            $h = self::kamar($b['total_price'], $malam, $tarif, (int) $b['incl'] === 1);
            $id = (int) $b['bookedid'];
            $st->bind_param('diddddi', $h['harga_per_malam'], $h['jumlah_malam'], $h['bruto'], $h['dpp'], $h['pbjt'], $h['tarif_snapshot'], $id);
            $st->execute();
            $n++;
        }
        $st->close();
        return $n;
    }

    /**
     * Restoran: dpp/pbjt per order dari bill (subtotal − diskon + service charge).
     * $koreksiBillBelumBayar: dipakai hook aplikasi tenant — bill yang BELUM dibayar diselaraskan
     * (VAT = PBJT engine, bill_amount & totalamount = total). Bill yang sudah dibayar tidak diubah
     * nominalnya (jurnal pembayaran sudah terposting); hanya dpp/pbjt-nya dicatat.
     * Engine oversight memanggil tanpa koreksi (Bapenda tidak mengubah data keuangan tenant).
     */
    public static function sinkronResto(mysqli $db, $tenantDb, $tarif, $koreksiBillBelumBayar = false)
    {
        $t = self::q($tenantDb);
        $inclR = $db->query("SELECT COALESCE(MAX(resto_price_includes_tax), 0) FROM $t.setting");
        $incl = $inclR ? (int) $inclR->fetch_row()[0] === 1 : false;
        $r = $db->query("SELECT o.order_id, o.totalamount, o.pbjt, o.pbjt_basis, o.tarif_snapshot,
                b.bill_id, b.total_amount, b.discount, b.service_charge, b.VAT, b.bill_amount, b.bill_status
            FROM $t.customer_order o LEFT JOIN $t.bill b ON b.order_id = o.order_id
            WHERE o.pbjt IS NULL OR o.pbjt_basis IS NULL
               OR (b.bill_id IS NOT NULL AND o.pbjt_basis <> ROUND(b.total_amount - b.discount + b.service_charge, 2))"
            . ($koreksiBillBelumBayar ? " OR (b.bill_status = 0 AND ROUND(b.VAT, 2) <> ROUND(IF(" . (int) $incl . ", 0, o.pbjt), 2))" : ''));
        if (!$r) return 0;
        $upO = $db->prepare("UPDATE $t.customer_order SET dpp=?, pbjt=?, tarif_snapshot=?, pbjt_basis=? WHERE order_id=?");
        if (!$upO) return 0; // DB tenant belum dimigrasi (010)
        $n = 0;
        while ($o = $r->fetch_assoc()) {
            if ($o['bill_id'] !== null) {
                $sub = (float) $o['total_amount'] - (float) $o['discount'];
                $sc = (float) $o['service_charge'];
            } else { // order tanpa bill (data lama): totalamount sebagai subtotal
                $sub = (float) $o['totalamount'];
                $sc = 0;
            }
            $basis = round($sub + $sc, 2);
            $sama = $o['pbjt'] !== null && $o['pbjt_basis'] !== null && round((float) $o['pbjt_basis'], 2) === $basis;
            $h = self::hitung($sub, $sc, $sama ? $o['tarif_snapshot'] : $tarif, $incl);
            $id = (int) $o['order_id'];
            $upO->bind_param('dddd' . 'i', $h['dpp'], $h['pbjt'], $h['tarif_snapshot'], $basis, $id);
            $upO->execute();
            if ($koreksiBillBelumBayar && $o['bill_id'] !== null && (int) $o['bill_status'] === 0) {
                $vat = $incl ? 0 : $h['pbjt'];
                $db->query("UPDATE $t.bill SET VAT=$vat, bill_amount={$h['total']} WHERE bill_id=" . (int) $o['bill_id']);
                $db->query("UPDATE $t.customer_order SET totalamount={$h['total']} WHERE order_id=$id");
            }
            $n++;
        }
        $upO->close();
        return $n;
    }
}
