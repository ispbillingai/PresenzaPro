-- Migration: 045_online_quick_signup
-- Online sign-up asks only name and mobile. Intolerances are asked once, with
-- the first order (intolerances_asked); address, landline and birthday go in
-- "Il mio profilo". Customers who signed up before already saw the
-- intolerances field, so they count as asked.

ALTER TABLE online_customers ADD COLUMN IF NOT EXISTS intolerances_asked TINYINT(1) NOT NULL DEFAULT 0 AFTER intolerances;
UPDATE online_customers SET intolerances_asked = 1;
