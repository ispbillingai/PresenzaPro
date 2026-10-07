-- Migration: 021_marketing_consents
-- Marketing consent is given by the guest, in their own table page (t.php),
-- not by the waiter. marketing_consents holds each phone's current decision
-- (granted / declined / revoked) with the exact text they accepted, when and
-- from where; marketing_consent_events keeps the full history (proof of
-- consent). Campaigns only go to phones whose status is 'granted'.
-- The consent ticks the waiter could set (orders / order_seat_guests
-- .marketing_consent) were not given by the guest and are not carried over.

CREATE TABLE IF NOT EXISTS marketing_consents (
    phone         VARCHAR(20) NOT NULL PRIMARY KEY,
    status        ENUM('granted', 'declined', 'revoked') NOT NULL,
    consent_text  TEXT NULL,
    lang          CHAR(2) NULL DEFAULT NULL,
    source        VARCHAR(20) NOT NULL DEFAULT 'guest_page',
    order_id      INT NULL DEFAULT NULL,
    ip_address    VARCHAR(45) NULL DEFAULT NULL,
    decided_at    DATETIME NOT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_consent_events (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    phone         VARCHAR(20) NOT NULL,
    action        ENUM('granted', 'declined', 'revoked') NOT NULL,
    consent_text  TEXT NULL,
    source        VARCHAR(20) NOT NULL,
    order_id      INT NULL DEFAULT NULL,
    ip_address    VARCHAR(45) NULL DEFAULT NULL,
    user_agent    VARCHAR(255) NULL DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_consent_events_phone (phone, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numbers that already unsubscribed from an invitation stay out.
INSERT IGNORE INTO marketing_consents (phone, status, source, decided_at)
    SELECT phone, 'revoked', source, created_at FROM marketing_optouts;
