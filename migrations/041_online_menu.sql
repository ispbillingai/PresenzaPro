-- Migration: 041_online_menu
-- "Menu online": the only menu online customers see (online.php), managed in
-- Admin > Menu online. Its categories are marked online_only and stay out of
-- the tables' menus, the PDF, the till and the waiters (like till_only).

ALTER TABLE menu_categories ADD COLUMN IF NOT EXISTS online_only TINYINT(1) NOT NULL DEFAULT 0;
