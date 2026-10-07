-- Migration: 037_till_customer_qr
-- Clienti cassa: each customer's code also as a QR (read at the till with the
-- scanner). The QR image (till-qr.php, sent on WhatsApp when the customer is
-- registered) is reached by a secret token, so images can't be listed by code.

ALTER TABLE till_customers ADD COLUMN IF NOT EXISTS qr_token VARCHAR(32) NULL DEFAULT NULL;
ALTER TABLE till_customers ADD UNIQUE INDEX IF NOT EXISTS uq_till_customers_qr_token (qr_token);
