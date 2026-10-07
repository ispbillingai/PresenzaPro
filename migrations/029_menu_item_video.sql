-- Migration: 029_menu_item_video
-- A short video of the dish (MP4 H.264 or WebM, played by the browser itself
-- with <video>, no plugin), shown on the public menu and in the guests' menu.

ALTER TABLE menu_items ADD COLUMN video_url VARCHAR(255) NULL DEFAULT NULL AFTER image_url;
