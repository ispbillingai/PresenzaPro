-- Migration: 018_new_tables_collation
-- The tables added by migrations 012-016 were created with the server's
-- default collation for utf8mb4 (utf8mb4_general_ci), while the rest of the
-- database is utf8mb4_unicode_ci. Mixing them in one query (e.g. the admin
-- Customers list, a UNION of orders and order_seat_guests) fails with
-- "Illegal mix of collations". Align them with the rest of the database.

ALTER TABLE table_requests    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE whatsapp_outbox   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE order_seat_guests CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE password_resets   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
