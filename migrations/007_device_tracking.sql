-- Origin of every clocking: device code (browser-persistent), full user agent, client details (JSON).
ALTER TABLE clockings
    MODIFY user_agent VARCHAR(500) NULL,
    ADD COLUMN device_id VARCHAR(40) NULL AFTER user_agent,
    ADD COLUMN client_info TEXT NULL AFTER device_id,
    ADD KEY ix_clockings_device (device_id);

CREATE TABLE IF NOT EXISTS devices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    device_id VARCHAR(40) NOT NULL,
    platform VARCHAR(120) NULL,
    user_agent VARCHAR(500) NULL,
    last_ip VARCHAR(45) NULL,
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    uses INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_device (user_id, device_id),
    CONSTRAINT fk_dev_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
