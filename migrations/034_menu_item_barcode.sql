-- Migration: 034_menu_item_barcode
-- Menu cassa products can carry the code of their own QR / barcode
-- (Admin > Menu cassa): scanned at the till (Ordini Cassa), it puts the
-- product straight on the ticket. One product per code.

ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS barcode VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE menu_items ADD UNIQUE INDEX IF NOT EXISTS uq_menu_items_barcode (barcode);
