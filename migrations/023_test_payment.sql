-- Migration: 023_test_payment
-- "Virtual payment" button at the till while testing (Settings > Test mode):
-- the bill is closed as paid with method 'test', no money and no fiscal receipt.

ALTER TABLE payments
    MODIFY COLUMN method ENUM('cash','card','mpesa','other','cash_machine','dojo','glovo','test') NOT NULL;
