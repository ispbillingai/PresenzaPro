-- Migration: 042_customer_birth_date
-- The customer's date of birth, asked when they sign up online and in the till's
-- "Dati cliente" box (optional): for birthday wishes and a gift coupon.

ALTER TABLE online_customers ADD COLUMN IF NOT EXISTS birth_date DATE NULL DEFAULT NULL;
ALTER TABLE till_customers ADD COLUMN IF NOT EXISTS birth_date DATE NULL DEFAULT NULL;
