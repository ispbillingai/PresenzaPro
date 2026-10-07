-- Migration: 015_whatsapp_outbox
-- WhatsApp to guests (TextMeBot): the table QR link as soon as a guest gives
-- their number, and the bill (a non-fiscal copy of the receipt) on request.
-- Messages go through an outbox drained by a background worker
-- (bin/whatsapp-worker.php), so the waiter never waits for TextMeBot's
-- mandatory gap between two messages.
--
-- order_seat_guests: a guest's own number for one seat, so each separate
-- (per-seat) bill can go to that guest's WhatsApp.

CREATE TABLE IF NOT EXISTS whatsapp_outbox (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    order_id    INT NULL DEFAULT NULL,
    seat        TINYINT UNSIGNED NULL DEFAULT NULL,
    kind        VARCHAR(20) NOT NULL,            -- table_link | bill
    phone       VARCHAR(20) NOT NULL,
    body        TEXT NOT NULL,
    status      ENUM('queued', 'sending', 'sent', 'failed') NOT NULL DEFAULT 'queued',
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error       VARCHAR(255) NULL DEFAULT NULL,
    created_by  INT NULL DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at     TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_wa_outbox_status (status, id),
    INDEX idx_wa_outbox_order (order_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_seat_guests (
    order_id          INT NOT NULL,
    seat              TINYINT UNSIGNED NOT NULL,
    customer_name     VARCHAR(120) NULL DEFAULT NULL,
    customer_country  CHAR(2) NULL DEFAULT NULL,
    customer_phone    VARCHAR(20) NULL DEFAULT NULL,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id, seat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
