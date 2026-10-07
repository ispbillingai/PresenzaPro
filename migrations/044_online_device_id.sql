-- Migration: 044_online_device_id
-- Online customers: the device they use. A web page can't read a phone's MAC
-- address, so online.php gives each browser a random device code (cookie,
-- 2 years) and it is kept with the sign-up and with every access and order.

ALTER TABLE online_customers ADD COLUMN IF NOT EXISTS registration_device CHAR(32) NULL DEFAULT NULL AFTER registration_ip;
ALTER TABLE online_customers ADD COLUMN IF NOT EXISTS last_device CHAR(32) NULL DEFAULT NULL AFTER last_ip;
ALTER TABLE online_customer_access ADD COLUMN IF NOT EXISTS device_id CHAR(32) NULL DEFAULT NULL AFTER ip_address;
ALTER TABLE online_customer_access ADD INDEX IF NOT EXISTS idx_online_access_device (device_id);
