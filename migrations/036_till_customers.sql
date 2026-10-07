-- Migration: 036_till_customers
-- "Clienti cassa": customers of counter sales whose details were taken in the
-- "Dati cliente" box at Ordini Cassa. Each gets a short code of its own
-- (C0001, C0002…): typed at the till next time, it brings their details back.
-- orders.till_customer_id links each counter sale to its customer.

CREATE TABLE IF NOT EXISTS till_customers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(12) NULL DEFAULT NULL,
    first_name     VARCHAR(60) NULL DEFAULT NULL,
    last_name      VARCHAR(60) NULL DEFAULT NULL,
    address        VARCHAR(150) NULL DEFAULT NULL,
    street_number  VARCHAR(15) NULL DEFAULT NULL,
    phone          VARCHAR(20) NULL DEFAULT NULL,
    country        CHAR(2) NULL DEFAULT NULL,
    active         TINYINT(1) NOT NULL DEFAULT 1,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_till_customers_code (code),
    INDEX idx_till_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS till_customer_id INT NULL DEFAULT NULL;
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_orders_till_customer (till_customer_id);
