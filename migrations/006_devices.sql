-- Migration: 006_devices
-- Network device up/down monitoring for the shop LAN (192.168.100.0/24).
--
-- The MikroTik router (reachable from this server over WireGuard) is polled on a
-- schedule by bin/poll-devices.php, which pings each device THROUGH the router's
-- RouterOS API and records the result here. The admin "Devices" page reads this
-- table and auto-refreshes so staff can see, at a glance, which POS hardware is
-- online. One row per monitored device; the poller updates status in place.

CREATE TABLE IF NOT EXISTS devices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    ip              VARCHAR(45) NOT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    status          ENUM('up','down','unknown') NOT NULL DEFAULT 'unknown',
    latency_ms      DECIMAL(8,2) NULL,
    last_seen_at    DATETIME NULL,        -- last time it answered a ping
    last_checked_at DATETIME NULL,        -- last time the poller reported on it
    active          TINYINT(1) NOT NULL DEFAULT 1,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the known shop devices. ON DUPLICATE keeps names/order in sync on re-run
-- without wiping the live status columns the poller maintains.
INSERT INTO devices (name, ip, sort_order) VALUES
    ('Order',          '192.168.100.10', 10),
    ('Fiscal printer', '192.168.100.11', 20),
    ('Cashier PC',     '192.168.100.12', 30),
    ('Cashmatic',      '192.168.100.13', 40),
    ('POS',            '192.168.100.14', 50)
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order);
