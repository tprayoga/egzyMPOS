-- Metode bayar booking "Virtual Account BNI" (SNAP BI) + akun kas penampungnya.
-- id 20 dipakai tetap oleh kode (config snap_bi.php: booking_payment_method_id).
-- Hanya tampil di checkout bila hotel punya profil kredensial BNI (lihat snap_bi.local.php).
INSERT IGNORE INTO payment_method (payment_method_id, payment_method, is_active)
VALUES (20, 'Virtual Account BNI', 1);

-- acc_coa ber-PRIMARY KEY HeadName, jadi INSERT IGNORE aman diulang.
INSERT IGNORE INTO acc_coa (HeadCode, HeadName, PHeadName, HeadLevel, IsActive, IsTransaction, IsGL, HeadType,
                            IsBudget, IsDepreciation, DepreciationRate, CreateBy, CreateDate, UpdateBy, UpdateDate)
VALUES ('102010305', 'BNI Virtual Account', 'Online Payment', 4, 1, 1, 0, 'A', 0, 0, 0.00, 1, NOW(), '', '0000-00-00 00:00:00');
