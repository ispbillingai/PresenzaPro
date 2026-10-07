-- Migration: 009_glovo
-- Glovo delivery orders (Glovo Partners API). Orders arriving from Glovo are
-- normal orders with channel = 'glovo' so they flow through the same kitchen
-- tickets / kitchen display; external_id is Glovo's order_id (deduplicates
-- webhook retries), external_meta keeps the raw Glovo payload + sync state.

ALTER TABLE orders
    ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'dine_in' AFTER till_id,
    ADD COLUMN external_id VARCHAR(64) NULL DEFAULT NULL AFTER channel,
    ADD COLUMN external_meta LONGTEXT NULL DEFAULT NULL AFTER external_id;

ALTER TABLE orders
    ADD UNIQUE INDEX uq_orders_channel_external (channel, external_id);

-- Glovo pays the restaurant, not the customer at the till.
ALTER TABLE payments
    MODIFY COLUMN method ENUM('cash','card','mpesa','other','cash_machine','dojo','glovo') NOT NULL;

-- Glovo product id -> our menu item, for products whose Glovo id is not simply
-- our menu_items.id (the default mapping).
CREATE TABLE IF NOT EXISTS glovo_product_map (
    glovo_product_id VARCHAR(64) NOT NULL PRIMARY KEY,
    menu_item_id     INT NOT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
