-- Migration: 013_request_swap
-- A guest's "change a dish" request can also ask to swap the dish for another
-- one from the menu: replacement_menu_item_id is that dish (NULL = just modify
-- the same dish, as written in message).

ALTER TABLE table_requests
    ADD COLUMN replacement_menu_item_id INT NULL DEFAULT NULL AFTER order_item_id;
