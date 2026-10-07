-- Migration: 016_password_reset
-- Forgotten password: the user gives their email, gets a link (valid 30 min,
-- single use); the reset page also asks for a 6-digit code sent on WhatsApp to
-- the user's phone. Only hashes of the link token and of the code are stored.
-- users.phone_country: the country picked for the user's WhatsApp number prefix.

ALTER TABLE users
    ADD COLUMN phone_country CHAR(2) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS password_resets (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    token_hash     CHAR(64) NOT NULL,
    expires_at     DATETIME NOT NULL,
    otp_hash       CHAR(64) NULL DEFAULT NULL,
    otp_expires_at DATETIME NULL DEFAULT NULL,
    otp_sent_at    DATETIME NULL DEFAULT NULL,
    otp_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at        DATETIME NULL DEFAULT NULL,
    ip_address     VARCHAR(45) NULL DEFAULT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_resets_token (token_hash),
    INDEX idx_password_resets_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
