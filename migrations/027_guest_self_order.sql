-- Migration: 027_guest_self_order
-- Orders a guest opened and sends to the kitchen from the table page
-- (Settings > Guest ordering): they may keep adding dishes from there.

ALTER TABLE orders ADD COLUMN created_by_guest TINYINT(1) NOT NULL DEFAULT 0;
