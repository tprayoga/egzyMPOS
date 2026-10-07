-- Contoh migrasi: tambah kolom penanda sinkronisasi terakhir pada tabel setting.
-- Idempoten di MariaDB via IF NOT EXISTS; pelacakan versi juga mencegah re-apply.
ALTER TABLE setting ADD COLUMN IF NOT EXISTS pbjt_last_sync DATETIME NULL;
