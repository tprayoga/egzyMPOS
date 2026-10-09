-- Customer type tercatat ganda (Walk In, Online, Hotel, ... masing-masing 2x) karena seed modul
-- ordermanage terpasang dua kali. Pindahkan referensi order ke id terkecil bernama sama, lalu hapus duplikat.
UPDATE customer_order o
  JOIN customer_type d ON d.customer_type_id = o.cutomertype
  JOIN (SELECT customer_type, MIN(customer_type_id) keep_id FROM customer_type GROUP BY customer_type) k
    ON k.customer_type = d.customer_type
   SET o.cutomertype = k.keep_id
 WHERE o.cutomertype <> k.keep_id;
DELETE d FROM customer_type d
  JOIN (SELECT customer_type, MIN(customer_type_id) keep_id FROM customer_type GROUP BY customer_type) k
    ON k.customer_type = d.customer_type
 WHERE d.customer_type_id <> k.keep_id;
UPDATE customer_type SET customer_type = 'Take Away' WHERE customer_type = 'Take Way';
