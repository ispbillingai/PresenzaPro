-- Migration: 043_coupon_qr
-- Coupons go out on WhatsApp with a QR of their code (coupon-qr.php), read at
-- the till with the scanner. The image is reached by a secret token, so
-- images can't be listed by code.

ALTER TABLE coupons ADD COLUMN IF NOT EXISTS qr_token VARCHAR(32) NULL DEFAULT NULL;
ALTER TABLE coupons ADD UNIQUE INDEX IF NOT EXISTS uq_coupons_qr_token (qr_token);
