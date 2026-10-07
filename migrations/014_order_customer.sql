-- Migration: 014_order_customer
-- The guest's details the waiter takes with the order: name and surname, the
-- city they come from, and their phone number (full international form, e.g.
-- +393331234567; customer_country is the ISO code picked for the prefix).

ALTER TABLE orders
    ADD COLUMN customer_name VARCHAR(120) NULL DEFAULT NULL,
    ADD COLUMN customer_city VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN customer_country CHAR(2) NULL DEFAULT NULL,
    ADD COLUMN customer_phone VARCHAR(20) NULL DEFAULT NULL;
