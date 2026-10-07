-- Migration: 033_till_menu
-- "Menu cassa": products only the till sees (Admin > Menu cassa). They live in
-- categories marked till_only, which every other menu (guests' QR pages, the
-- PDF, online ordering, waiters, the normal menu admin) leaves out. At the
-- till (Cassa > Ordini online) they are buttons; with the keypad's free
-- amounts they make a counter sale (orders.channel = 'counter') or go on an
-- online customer's bill.

ALTER TABLE menu_categories ADD COLUMN IF NOT EXISTS till_only TINYINT(1) NOT NULL DEFAULT 0;
