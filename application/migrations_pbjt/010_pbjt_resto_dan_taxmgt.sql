-- PBJT restoran lewat engine yang sama dengan kamar (keputusan 2026-10-09).
ALTER TABLE customer_order
  ADD COLUMN IF NOT EXISTS dpp DECIMAL(15,2) NULL,
  ADD COLUMN IF NOT EXISTS pbjt DECIMAL(15,2) NULL,
  ADD COLUMN IF NOT EXISTS tarif_snapshot DECIMAL(6,4) NULL,
  ADD COLUMN IF NOT EXISTS pbjt_basis DECIMAL(15,2) NULL COMMENT 'subtotal - diskon + service charge saat dihitung';
-- Per outlet: 1 = harga menu sudah termasuk PBJT, 0 = PBJT ditambahkan (default restoran).
ALTER TABLE setting ADD COLUMN IF NOT EXISTS resto_price_includes_tax TINYINT(1) NOT NULL DEFAULT 0;

-- Tax Management hanya untuk komponen non-PBJT. Entri pajak daerah (PB1/PBJT/pajak hotel/restoran)
-- dinonaktifkan agar tidak terhitung ganda dengan engine PBJT; nilai lamanya dicatat.
CREATE TABLE IF NOT EXISTS pbjt_taxmgt_nonaktif (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tax_id INT NOT NULL,
  taxname TEXT NOT NULL,
  rate DECIMAL(8,2) NULL,
  reg_no TEXT NULL,
  isactive_lama INT NULL,
  alasan VARCHAR(255) NOT NULL,
  dinonaktifkan_pada DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO pbjt_taxmgt_nonaktif (tax_id, taxname, rate, reg_no, isactive_lama, alasan, dinonaktifkan_pada)
SELECT tax_id, taxname, rate, reg_no, isactive, 'Pajak daerah: PBJT hanya dihitung engine PBJT (migrasi 010)', NOW()
  FROM tbl_taxmgt
 WHERE isactive = 1
   AND LOWER(taxname) REGEXP '(^|[^a-z])(pb ?1|pbjt|pajak (daerah|hotel|restoran|resto|rumah makan|penginapan|barang dan jasa)|hotel tax|restaurant tax)';
INSERT INTO accesslog (action_page, action_done, remarks, user_name, entry_date)
SELECT 'Tax Management', 'Nonaktifkan', CONCAT('Migrasi 010: "', taxname, '" (', rate, '%) dinonaktifkan - PBJT hanya dari engine PBJT'), 'sistem', NOW()
  FROM pbjt_taxmgt_nonaktif WHERE alasan LIKE '%migrasi 010%' AND dinonaktifkan_pada >= NOW() - INTERVAL 1 MINUTE;
UPDATE tbl_taxmgt t JOIN pbjt_taxmgt_nonaktif n ON n.tax_id = t.tax_id SET t.isactive = 0 WHERE t.isactive = 1;
