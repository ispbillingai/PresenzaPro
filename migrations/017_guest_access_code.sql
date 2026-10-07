-- Migration: 017_guest_access_code
-- The table QR is fixed; what opens the guest service is a per-order access
-- code. When a guest leaves their phone number with the order, a random
-- 6-digit code is sent to them on WhatsApp together with the table link; the
-- QR page asks for it. The code dies with the order, so the next guests at the
-- table can't see it. guest_code_attempts counts wrong codes (lock after 10).

ALTER TABLE orders
    ADD COLUMN guest_code CHAR(6) NULL DEFAULT NULL,
    ADD COLUMN guest_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;
