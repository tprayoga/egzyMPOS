-- Kolom password cukup untuk hash bcrypt/argon (dulu varchar(32) = md5).
-- Hash md5 lama tetap berlaku dan di-upgrade otomatis saat user login.
ALTER TABLE user MODIFY password VARCHAR(255) NOT NULL;
ALTER TABLE customerinfo MODIFY pass VARCHAR(255) NULL;
