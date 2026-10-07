-- Migration: 007_dojo_payment
-- Adds Dojo terminal (Dojo Cloud API "Pay at Counter") as a card payment method.
-- Dojo reuses the existing card_* columns on `payments` (it IS a card payment);
-- Dojo-specific ids (payment intent, terminal session) live in device_meta JSON.
-- Only two enums need widening.

-- New payment method value.
ALTER TABLE payments
    MODIFY COLUMN method ENUM('cash','card','mpesa','other','cash_machine','dojo') NOT NULL;

-- New device value for the audit log (device_events).
ALTER TABLE device_events
    MODIFY COLUMN device ENUM('cashmatic','card','fiscal','qr','system','dojo') NOT NULL;
