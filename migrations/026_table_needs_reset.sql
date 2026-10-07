-- Migration: 026_table_needs_reset
-- A table whose guests have paid and left is marked "to be laid again" until
-- a waiter taps "Laid" on the floor plan (or a new order opens on it).
-- NULL = ready.

ALTER TABLE tables_restaurant ADD COLUMN needs_reset_at DATETIME NULL DEFAULT NULL;
