-- Perbaikan teks UI dari Skenario Uji (lembar Temuan Awal no. 12 & Perbaikan desain no. 5).
UPDATE language SET english = 'NIK' WHERE phrase = 'ssn';
UPDATE language SET english = 'Booking Source' WHERE phrase = 'booking_source';
UPDATE language SET english = 'Factory reset' WHERE phrase = 'factory_rest';
UPDATE language SET english = 'Airline Name' WHERE phrase = 'airlience_name';
UPDATE language SET english = 'Checklist' WHERE phrase = 'checklist';
UPDATE language SET english = 'Received' WHERE phrase = 'recieved';
UPDATE language SET english = 'Receive' WHERE phrase = 'recieve';
UPDATE language SET english = 'Whatsapp Phone Number' WHERE phrase = 'whatsapp_phone_numer';
UPDATE language SET english = 'Whatsapp Phone Number (International Standard)' WHERE phrase = 'whatsapp_phone_numer_internation_standard';
UPDATE tbl_category SET categoryname = 'Housekeeping products' WHERE category_id = 501 AND categoryname = 'Houekeeping products';
UPDATE tbl_soundsetting SET nofitysound = 'assets/beep-08b.mp3' WHERE nofitysound = 'assets/2021-03-21/b1.mp3';
