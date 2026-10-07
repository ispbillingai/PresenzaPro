-- Migration: 038_till_customer_no_receipt
-- Clienti cassa: a counter sale paid with a known customer sends them the
-- receipt on WhatsApp with their QR; no_receipt = 1 is the tick in Admin >
-- Clienti cassa for a customer who does not want it.

ALTER TABLE till_customers ADD COLUMN IF NOT EXISTS no_receipt TINYINT(1) NOT NULL DEFAULT 0;
