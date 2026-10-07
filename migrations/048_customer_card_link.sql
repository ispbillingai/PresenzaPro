-- Migration: 048_customer_card_link
-- One customer, one card: a Clienti cassa customer (the card, with its code
-- and QR) is linked to the online customer with the same phone
-- (includes/customer_card.php links the existing ones and makes the missing cards).

ALTER TABLE till_customers ADD COLUMN IF NOT EXISTS online_customer_id INT NULL DEFAULT NULL AFTER fiscal_code;
ALTER TABLE till_customers ADD INDEX IF NOT EXISTS idx_till_customers_online (online_customer_id);
