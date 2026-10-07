-- Migration: 024_restaurant_contacts
-- The restaurant's address, phone, website and social links (Settings >
-- Restaurant settings). Shown on the receipt, the order PDF and the guests'
-- table page.

ALTER TABLE workspaces
    ADD COLUMN address_street     VARCHAR(150) NULL DEFAULT NULL,
    ADD COLUMN address_number     VARCHAR(20)  NULL DEFAULT NULL,
    ADD COLUMN postal_code        VARCHAR(10)  NULL DEFAULT NULL,
    ADD COLUMN city               VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN phone              VARCHAR(30)  NULL DEFAULT NULL,
    ADD COLUMN website            VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN social_facebook    VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN social_instagram   VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN social_tiktok      VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN social_tripadvisor VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN social_google      VARCHAR(255) NULL DEFAULT NULL;
