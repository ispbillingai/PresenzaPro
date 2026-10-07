-- Migration: 028_guest_order_waiter
-- A guest's own order (table QR) has no waiter at first: the first "dish
-- ready" goes to every waiter, and whoever taps "I'll take it" becomes the
-- table's waiter — the later alerts and the table's calls go to them only.

ALTER TABLE orders ADD COLUMN assigned_waiter_id INT NULL DEFAULT NULL;
