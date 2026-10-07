-- Migration: 002_payment_devices
-- Adds support for Cashmatic (automated cash), Ingenico card terminal and
-- Epson fiscal printer to the payments flow, plus a device audit log.
--
-- NOTE: the live `payments` table uses columns: method, reference, received_by
-- (see database_schema.sql). This migration extends that table.

-- Add Cashmatic as a payment method (automated cash machine).
ALTER TABLE payments
    MODIFY COLUMN method ENUM('cash','card','mpesa','other','cash_machine') NOT NULL;

-- Device / payment metadata captured at payment time.
ALTER TABLE payments
    ADD COLUMN currency_code            SMALLINT UNSIGNED NULL AFTER amount,
    ADD COLUMN cashmatic_transaction_id INT          NULL AFTER reference,
    ADD COLUMN card_transaction_id      VARCHAR(64)  NULL AFTER cashmatic_transaction_id,
    ADD COLUMN card_auth_code           VARCHAR(32)  NULL AFTER card_transaction_id,
    ADD COLUMN card_pan_masked          VARCHAR(32)  NULL AFTER card_auth_code,
    ADD COLUMN fiscal_receipt_number    VARCHAR(64)  NULL AFTER card_pan_masked,
    ADD COLUMN fiscal_status            ENUM('none','printed','failed') NOT NULL DEFAULT 'none' AFTER fiscal_receipt_number,
    ADD COLUMN device_meta              JSON         NULL AFTER fiscal_status;

-- Append-only audit log of device interactions (mirrors parking gate_events).
CREATE TABLE IF NOT EXISTS device_events (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT NULL,
    payment_id  INT NULL,
    device      ENUM('cashmatic','card','fiscal','qr','system') NOT NULL,
    event_type  VARCHAR(50) NOT NULL,
    details     JSON NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_order (order_id),
    KEY idx_device (device),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
