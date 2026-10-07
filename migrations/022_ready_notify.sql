-- Migration: 022_ready_notify
-- Who gets the "dish ready" notification for an order, when it differs from
-- the general rule set in Settings: NULL = the general rule, 'order_waiter',
-- 'all' (every waiter) or 'user:<id>' (one chosen waiter).

ALTER TABLE orders ADD COLUMN ready_notify VARCHAR(20) NULL DEFAULT NULL AFTER waiter_id;
