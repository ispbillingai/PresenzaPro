-- Migration: 007_network_areas
-- Network areas = the MikroTik routers the admin manages from the panel. Each
-- area is one router reached over WireGuard; the device poller logs into its
-- RouterOS API and pings the devices that belong to that area.
--
-- Before this, the single router lived hardcoded in config/devices.php. Now
-- routers are DB-backed so admins can add/edit them from Admin -> Network areas.
-- Devices gain an optional area_id so each is pinged through its own router;
-- a device with no area falls back to the first active area.

CREATE TABLE IF NOT EXISTS network_areas (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    host        VARCHAR(100) NOT NULL,          -- router address (over WireGuard)
    api_port    INT NOT NULL DEFAULT 8728,      -- RouterOS API port (8728 plain / 8729 SSL)
    api_user    VARCHAR(100) NOT NULL DEFAULT 'admin',
    api_pass    VARCHAR(255) NOT NULL DEFAULT '',
    ping_count  INT NOT NULL DEFAULT 2,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link devices to an area (nullable; poller falls back to first active area).
ALTER TABLE devices
    ADD COLUMN area_id INT UNSIGNED NULL DEFAULT NULL AFTER ip;

-- Seed the router we already have. INSERT only if the table is empty, so this is
-- safe to re-run and never overwrites a password the admin later edits in the UI.
INSERT INTO network_areas (name, host, api_port, api_user, api_pass, ping_count, sort_order)
SELECT 'Panificio Azzurro', '192.168.200.15', 8728, 'admin', '', 2, 10
WHERE NOT EXISTS (SELECT 1 FROM network_areas);

-- Attach the seeded shop devices to that first area.
UPDATE devices SET area_id = (SELECT id FROM network_areas ORDER BY sort_order, id LIMIT 1)
WHERE area_id IS NULL;
