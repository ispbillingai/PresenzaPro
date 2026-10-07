-- Migration: 008_item_stations
-- Per-DISH work point.
--
-- Until now a dish's printer was decided by its menu CATEGORY
-- (menu_categories.station_id, migration 004). A category is often too coarse:
-- "Secondi" can hold grilled meat (grill) next to fried fish (fryer), and a
-- single "Primi" category can span kitchen and pizza oven. A dish can now name
-- its own work point; NULL keeps inheriting its category's, so nothing changes
-- for menus that are already routed correctly by category.
--
-- Resolution order at print time (includes/kitchen_ticket.php):
--   menu_items.station_id  ->  menu_categories.station_id  ->  default kitchen printer

ALTER TABLE menu_items
    ADD COLUMN station_id INT NULL DEFAULT NULL AFTER preparation_time;
