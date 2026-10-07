---
name: users-never-deleted
description: "User deletion rule — delete only if no orders/payments, else disable"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: d741810f-5fbd-4f7a-a79a-de8d79d022af
---

Admin can **delete** a user, but ONLY if the user has no history (no rows in
`orders.waiter_id` or `payments.received_by`). If they have history, deletion is
refused and they must be **disabled** instead — deleting would break those
records (FK without cascade). Users with history can still be enabled/disabled.

Implemented in `admin/users.php` (`delete_user` action checks refs first;
`toggle_status` for enable/disable). You can't delete your own account. Login
filters `WHERE active = 1`. See [[parking-reference-app]].
