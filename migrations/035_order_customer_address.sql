-- Migration: 035_order_customer_address
-- Orders paid at Ordini Cassa (counter sales, online orders) can take the
-- customer's details at the till: name and surname (customer_name), phone
-- (customer_phone / customer_country) and now the address with house number.

ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_address VARCHAR(150) NULL DEFAULT NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_street_number VARCHAR(15) NULL DEFAULT NULL;
