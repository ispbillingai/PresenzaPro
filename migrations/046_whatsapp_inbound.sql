-- Migration: 046_whatsapp_inbound
-- WhatsApp messages coming in (TextMeBot webhook → api/whatsapp-inbound.php):
-- whatsapp_inbox logs each one (text kept only for the ones we handle; the
-- others go on to the previous webhook, the chatbot). online_wa_logins: the
-- "Entra con WhatsApp" sign-ins — the code in the ready-made message, then the
-- number it came from and the one-time link we answer with.

CREATE TABLE IF NOT EXISTS whatsapp_inbox (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    from_phone      VARCHAR(20) NULL DEFAULT NULL,
    msg_type        VARCHAR(20) NULL DEFAULT NULL,
    message         TEXT NULL,
    handled         VARCHAR(30) NULL DEFAULT NULL,      -- login_code | keyword | NULL (forwarded)
    forward_status  INT NULL DEFAULT NULL,              -- HTTP status of the forward
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inbox_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS online_wa_logins (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    code         CHAR(6) NOT NULL,
    link_token   CHAR(32) NULL DEFAULT NULL,
    phone        VARCHAR(20) NULL DEFAULT NULL,
    from_name    VARCHAR(120) NULL DEFAULT NULL,
    lang         CHAR(2) NOT NULL DEFAULT 'it',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at  DATETIME NULL DEFAULT NULL,
    used_at      DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_wa_login_code (code),
    UNIQUE KEY uq_wa_login_link (link_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
