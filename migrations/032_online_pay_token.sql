-- Migration: 032_online_pay_token
-- Online customers pay at the till by showing a QR (on their page and in the
-- "order ready" WhatsApp). The QR carries the order's secret pay_token; the
-- cashier scans it in Cassa > Ordini online and lands on that order's payment.

ALTER TABLE orders ADD COLUMN IF NOT EXISTS pay_token VARCHAR(32) NULL DEFAULT NULL;
ALTER TABLE orders ADD UNIQUE INDEX IF NOT EXISTS uq_orders_pay_token (pay_token);
