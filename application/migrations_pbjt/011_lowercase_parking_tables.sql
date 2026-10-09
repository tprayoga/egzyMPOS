-- Kode memanggil tbl_bookparking / tbl_parkingsetting (huruf kecil). Di Linux nama tabel peka huruf
-- besar/kecil (rename lewat nama sementara agar juga jalan di macOS yang tidak peka huruf): bila tabel dibuat sebagai tbl_bookParking / tbl_parkingSetting, query gagal dan
-- pilihan zona parkir tak pernah muncul (Skenario Uji TC-041/042). Ganti nama bila ditemukan persis.
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND BINARY table_name = 'tbl_bookParking') THEN
    RENAME TABLE `tbl_bookParking` TO `tbl_bookparking_011tmp`, `tbl_bookparking_011tmp` TO `tbl_bookparking`;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND BINARY table_name = 'tbl_parkingSetting') THEN
    RENAME TABLE `tbl_parkingSetting` TO `tbl_parkingsetting_011tmp`, `tbl_parkingsetting_011tmp` TO `tbl_parkingsetting`;
  END IF;
END;
