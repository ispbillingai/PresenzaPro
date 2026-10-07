-- Migration: 025_notification_table_free
-- "Table free, clear it and lay it again": sent to every waiter once the
-- table's bill is paid (includes/ready_notify.php notifyTableFreed).

ALTER TABLE notifications
    MODIFY COLUMN type ENUM('dish_ready','new_order','table_paid','bill_requested','general','table_free') NOT NULL;
