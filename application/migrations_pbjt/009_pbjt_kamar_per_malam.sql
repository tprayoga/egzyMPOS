-- PBJT kamar per booking (keputusan 2026-10-09). booked_info.total_price = harga per malam (termasuk
-- pajak bila listing price_includes_tax=1). Nilai diisi Pbjt_pajak::sinkronKamar() saat engine
-- menyinkronkan omzet, sehingga semua jalur penulisan booking ikut terhitung.
ALTER TABLE booked_info
  ADD COLUMN IF NOT EXISTS harga_per_malam DECIMAL(15,2) NULL,
  ADD COLUMN IF NOT EXISTS jumlah_malam INT NULL,
  ADD COLUMN IF NOT EXISTS bruto DECIMAL(15,2) NULL,
  ADD COLUMN IF NOT EXISTS dpp DECIMAL(15,2) NULL,
  ADD COLUMN IF NOT EXISTS pbjt DECIMAL(15,2) NULL,
  ADD COLUMN IF NOT EXISTS tarif_snapshot DECIMAL(6,4) NULL;
-- Per listing (tipe kamar): 1 = tarif sudah termasuk PBJT (default), 0 = belum termasuk.
ALTER TABLE roomdetails ADD COLUMN IF NOT EXISTS price_includes_tax TINYINT(1) NOT NULL DEFAULT 1;
