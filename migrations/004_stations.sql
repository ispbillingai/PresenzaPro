-- Migration: 004_stations
-- Work points / preparation departments (kitchen, bar, pizza oven, grill...).
-- Each has its own NON-FISCAL thermal printer. A menu category is routed to a
-- work point; when an order is sent, the dishes are split per work point and a
-- separate slip is printed at each area's printer. Categories with no work
-- point fall back to the default kitchen printer (admin/printers.php).
--
-- The `type` column is forward-looking: 'till' will let checkouts (with a
-- fiscal printer) be modelled here too. For now only 'prep' areas are used.

CREATE TABLE IF NOT EXISTS stations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    workspace_id     INT NOT NULL DEFAULT 1,
    name             VARCHAR(100) NOT NULL,
    type             ENUM('prep','till') NOT NULL DEFAULT 'prep',
    printer_enabled  TINYINT(1) NOT NULL DEFAULT 1,
    printer_host     VARCHAR(100) NULL,
    printer_port     INT NOT NULL DEFAULT 9100,
    printer_width    INT NOT NULL DEFAULT 32,
    printer_codepage INT NOT NULL DEFAULT 2,
    sort_order       INT NOT NULL DEFAULT 0,
    active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Route a whole menu category to a work point (NULL = default kitchen printer).
ALTER TABLE menu_categories
    ADD COLUMN station_id INT NULL DEFAULT NULL AFTER allow_composition;
