-- Replace personal login links with per-employee clocking webhooks (GET called on every accepted clocking).
ALTER TABLE users
    DROP INDEX uq_users_token,
    DROP COLUMN login_token,
    DROP COLUMN token_created_at,
    ADD COLUMN webhook_url VARCHAR(500) NULL AFTER phone,
    ADD COLUMN webhook_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER webhook_url;

CREATE TABLE IF NOT EXISTS webhook_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    clocking_id INT UNSIGNED NULL,
    url VARCHAR(1000) NOT NULL,
    http_code SMALLINT UNSIGNED NULL,
    error VARCHAR(255) NULL,
    duration_ms INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_wl_user (user_id, created_at),
    CONSTRAINT fk_wl_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (`key`, `value`) VALUES ('webhooks_enabled', '1');
