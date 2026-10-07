-- Migration: 047_till_customer_fiscal_code
-- Clienti cassa: the codice fiscale (read from the tessera sanitaria's barcode
-- at the till), with the sex and place of birth it gives. Reading the card
-- again recognises the customer.

ALTER TABLE till_customers ADD COLUMN IF NOT EXISTS fiscal_code CHAR(16) NULL DEFAULT NULL AFTER code;
ALTER TABLE till_customers ADD COLUMN IF NOT EXISTS sex CHAR(1) NULL DEFAULT NULL AFTER birth_date;
ALTER TABLE till_customers ADD COLUMN IF NOT EXISTS birth_place VARCHAR(120) NULL DEFAULT NULL AFTER sex;
ALTER TABLE till_customers ADD INDEX IF NOT EXISTS idx_till_customers_cf (fiscal_code);
