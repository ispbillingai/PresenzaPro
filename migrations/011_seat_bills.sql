-- Migration: 011_seat_bills
-- Bill by seat. The waiter puts each dish on a seat (order_items.seat; NULL =
-- shared by the table). "Bill seat N" moves that seat's dishes (and one cover)
-- into a SEAT BILL: a normal order with parent_order_id = the table's order and
-- seat = N, so it is paid through the usual cashier / card / cash-machine flow.
-- The table's own order (parent_order_id IS NULL) keeps holding the tables
-- until it and every seat bill are paid.
-- Plain ADD COLUMN (no IF NOT EXISTS) so it runs on MySQL 8 as well as MariaDB.

ALTER TABLE order_items
    ADD COLUMN seat TINYINT UNSIGNED NULL DEFAULT NULL AFTER order_id;

ALTER TABLE orders
    ADD COLUMN parent_order_id INT NULL DEFAULT NULL AFTER table_label,
    ADD COLUMN seat TINYINT UNSIGNED NULL DEFAULT NULL AFTER parent_order_id,
    ADD INDEX idx_orders_parent (parent_order_id);
