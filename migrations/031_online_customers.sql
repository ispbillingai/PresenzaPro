-- Migration: 031_online_customers
-- "Clienti online": a shop with no tables. One QR for everybody (online.php):
-- the customer signs up once (name, surname, address, house number, mobile
-- for the WhatsApp code, landline, intolerances, marketing consent) and then
-- orders from the menu; returning customers type their mobile and get a code.
-- online_customers keeps the details with the IP they signed up from;
-- online_customer_access logs every sign-up, login and order with its IP.
-- Their orders are normal orders with channel = 'online' (see includes/online_order.php).

CREATE TABLE IF NOT EXISTS online_customers (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    first_name         VARCHAR(60) NOT NULL,
    last_name          VARCHAR(60) NOT NULL,
    address            VARCHAR(150) NOT NULL,
    street_number      VARCHAR(15) NOT NULL,
    mobile             VARCHAR(20) NOT NULL,
    mobile_country     CHAR(2) NOT NULL DEFAULT 'IT',
    landline           VARCHAR(25) NULL DEFAULT NULL,
    intolerances       TEXT NULL,
    marketing_consent  TINYINT(1) NOT NULL DEFAULT 0,
    registration_ip    VARCHAR(45) NULL DEFAULT NULL,
    last_ip            VARCHAR(45) NULL DEFAULT NULL,
    last_seen_at       DATETIME NULL DEFAULT NULL,
    active             TINYINT(1) NOT NULL DEFAULT 1,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_online_customers_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS online_customer_access (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    customer_id  INT NOT NULL,
    event        VARCHAR(20) NOT NULL,           -- register | login | order
    ip_address   VARCHAR(45) NULL DEFAULT NULL,
    user_agent   VARCHAR(255) NULL DEFAULT NULL,
    order_id     INT NULL DEFAULT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_online_access_customer (customer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS online_customer_id INT NULL DEFAULT NULL;
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_orders_online_customer (online_customer_id, status);
