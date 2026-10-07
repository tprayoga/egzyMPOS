-- Contoh migrasi kedua: tabel catatan internal per-tenant (mis. memo operasional).
CREATE TABLE IF NOT EXISTS pbjt_catatan (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  isi VARCHAR(255) NOT NULL,
  dibuat DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
