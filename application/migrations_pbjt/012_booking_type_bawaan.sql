-- Pilihan Booking Type bawaan (usulan penguji, Skenario Uji lembar Perbaikan desain no. 3).
-- Hanya ditambahkan bila belum ada; tipe yang sudah dibuat hotel tidak diubah.
INSERT INTO bookingtype (booktypetitle)
SELECT v.t FROM (
  SELECT 'Walk-in' t UNION ALL SELECT 'Telepon / WhatsApp' UNION ALL SELECT 'Website Hotel'
  UNION ALL SELECT 'OTA (Traveloka, Tiket.com, dll.)' UNION ALL SELECT 'Korporat'
  UNION ALL SELECT 'Travel Agent' UNION ALL SELECT 'Grup / Event'
) v
WHERE NOT EXISTS (SELECT 1 FROM bookingtype b WHERE LOWER(b.booktypetitle) = LOWER(v.t));
