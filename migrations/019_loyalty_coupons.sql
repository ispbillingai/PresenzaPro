-- Migration: 019_loyalty_coupons
-- Loyalty coupons. Admin Settings holds rules like "3 visits in a month ->
-- 10% off, valid 60 days" (setting 'loyalty_rules'). When a guest's meal is
-- paid and their visits in the rule's period reach the threshold, a coupon
-- with a unique code is issued and sent on WhatsApp. The cashier redeems it
-- on an order (orders.coupon_id), which applies its discount.

CREATE TABLE IF NOT EXISTS coupons (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20) NOT NULL,
    phone           VARCHAR(20) NOT NULL,
    customer_name   VARCHAR(120) NULL DEFAULT NULL,
    rule_id         VARCHAR(32) NULL DEFAULT NULL,
    rule_name       VARCHAR(120) NULL DEFAULT NULL,
    discount_type   ENUM('percent', 'fixed') NOT NULL,
    discount_value  DECIMAL(10,2) NOT NULL,
    visits          INT NULL DEFAULT NULL,
    period          VARCHAR(10) NULL DEFAULT NULL,
    issued_at       DATETIME NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL DEFAULT NULL,
    used_order_id   INT NULL DEFAULT NULL,
    created_by      INT NULL DEFAULT NULL,
    UNIQUE KEY uq_coupons_code (code),
    INDEX idx_coupons_phone (phone, issued_at),
    INDEX idx_coupons_rule (rule_id, phone, issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN coupon_id INT NULL DEFAULT NULL;
