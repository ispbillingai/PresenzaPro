-- Migration: 040_till_operator_role
-- New user group "Operatore Ordini Cassa" (role 'till'): sees and works only
-- Ordini Cassa (cashier/online.php) and pays only its orders (counter sales,
-- online orders) — not the Cassa of the tables.

ALTER TABLE users MODIFY role ENUM('admin', 'waiter', 'cashier', 'kitchen', 'till') NOT NULL;
