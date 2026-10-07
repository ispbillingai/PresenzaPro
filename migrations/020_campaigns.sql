-- Migration: 020_campaigns
-- Advertising campaigns: WhatsApp invitations to events / initiatives, sent
-- only to guests who agreed to receive them (marketing_consent on the order or
-- on a seat guest) and never to numbers that unsubscribed (marketing_optouts,
-- via the link at the end of every invitation). Campaign messages go through
-- whatsapp_outbox with a low priority, so service messages (access codes,
-- bills, password codes) are never stuck behind a long campaign.

CREATE TABLE IF NOT EXISTS campaigns (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150) NOT NULL,
    message      TEXT NOT NULL,
    image_path   VARCHAR(255) NULL DEFAULT NULL,
    filters      TEXT NULL,
    recipients   INT NOT NULL DEFAULT 0,
    status       ENUM('draft', 'sending', 'sent') NOT NULL DEFAULT 'draft',
    created_by   INT NULL DEFAULT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at      DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_optouts (
    phone       VARCHAR(20) NOT NULL PRIMARY KEY,
    source      VARCHAR(20) NOT NULL DEFAULT 'link',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE whatsapp_outbox
    ADD COLUMN campaign_id INT NULL DEFAULT NULL,
    ADD COLUMN media_url VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN priority TINYINT NOT NULL DEFAULT 10,
    ADD INDEX idx_wa_outbox_prio (status, priority, id),
    ADD INDEX idx_wa_outbox_campaign (campaign_id, status);

ALTER TABLE orders
    ADD COLUMN marketing_consent TINYINT(1) NULL DEFAULT NULL;

ALTER TABLE order_seat_guests
    ADD COLUMN marketing_consent TINYINT(1) NULL DEFAULT NULL;
