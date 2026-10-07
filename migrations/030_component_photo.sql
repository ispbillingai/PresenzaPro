-- Migration: 030_component_photo
-- A photo for each ingredient of a dish (Menu > Components), shown where
-- the waiter or the guest takes ingredients off / adds extras.

ALTER TABLE menu_item_components ADD COLUMN image_url VARCHAR(255) NULL DEFAULT NULL;
