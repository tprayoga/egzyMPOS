-- Seragamkan charset/collation semua tabel ke utf8mb4_general_ci.
-- Skema warisan bercampur (latin1_*, utf8mb3_general_ci, utf8mb3_unicode_ci, *_uca1400_ai_ci bawaan
-- MariaDB baru), sehingga JOIN antar kolom teks gagal "Illegal mix of collations" — mis. menu
-- House Keeping > Records (HTTP 500). CONVERT TO menerjemahkan isi apa adanya (tidak ada data hilang).
SET FOREIGN_KEY_CHECKS = 0;
BEGIN NOT ATOMIC
  DECLARE done INT DEFAULT 0;
  DECLARE t VARCHAR(64);
  DECLARE c CURSOR FOR
    SELECT table_name FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
       AND (table_collation <> 'utf8mb4_general_ci'
            OR table_name IN (SELECT table_name FROM information_schema.columns
                               WHERE table_schema = DATABASE() AND collation_name IS NOT NULL
                                 AND collation_name <> 'utf8mb4_general_ci'));
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  OPEN c;
  l: LOOP
    FETCH c INTO t;
    IF done THEN LEAVE l; END IF;
    EXECUTE IMMEDIATE CONCAT('ALTER TABLE `', t, '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
  END LOOP;
  CLOSE c;
END;
SET FOREIGN_KEY_CHECKS = 1;
ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
