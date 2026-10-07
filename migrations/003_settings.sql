-- Migration: 003_settings
-- Key/value application settings edited from the admin panel — used for the
-- printer configuration (kitchen / cashier-bill / fiscal, with IPs).

CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value MEDIUMTEXT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
