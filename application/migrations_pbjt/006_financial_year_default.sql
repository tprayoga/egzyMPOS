-- Tahun buku aktif (kalender berjalan) bila belum ada sama sekali.
-- Tanpa baris ini form booking & pembayaran POS menolak simpan ("Please Create Financial Year First")
-- dan jurnal saldo awal gagal diam-diam. UI Accounts menolak tanggal mulai sebelum hari ini,
-- jadi tahun pertama tak bisa dibuat lewat UI untuk data yang sudah berjalan.
INSERT INTO tbl_financialyear (title, start_date, end_date, date_time, is_active, create_by)
SELECT CAST(YEAR(CURDATE()) AS CHAR), MAKEDATE(YEAR(CURDATE()), 1),
       DATE_SUB(MAKEDATE(YEAR(CURDATE()) + 1, 1), INTERVAL 1 DAY), NOW(), '2', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM tbl_financialyear);
