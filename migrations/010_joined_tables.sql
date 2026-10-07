-- Migration: 010_joined_tables
-- Join tables for a large party (e.g. 15 guests on tables 5+6+7): ONE order,
-- one bill. The order keeps its first table in orders.table_id; every joined
-- table points at the order through tables_restaurant.current_order_id (so
-- request-bill / payment already update and free all of them). table_label
-- holds the display name "5 + 6 + 7"; NULL = single table.

ALTER TABLE orders
    ADD COLUMN table_label VARCHAR(100) NULL DEFAULT NULL AFTER table_id;
