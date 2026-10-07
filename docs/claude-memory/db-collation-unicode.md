---
name: db-collation-unicode
description: New tables in the ristorante DB must declare COLLATE utf8mb4_unicode_ci — the server default gives general_ci and UNIONs/joins then fail live
metadata:
  node_type: memory
  type: project
  originSessionId: d9854651-e1f6-4195-a7b0-c032f8a647a5
  modified: 2026-09-30T05:28:21.532Z
---

The live ristorante database (MariaDB 10.11, db `ristorante`) is `utf8mb4_unicode_ci`, but `CREATE TABLE ... DEFAULT CHARSET=utf8mb4` without a collation gets the server default `utf8mb4_general_ci`. On 2026-09-30 the admin Customers page (a UNION of `orders` and `order_seat_guests`) failed only on the server with "Illegal mix of collations"; local tests passed because the local test DB defaulted to unicode_ci. Fixed by migration 018 (converted table_requests, whatsapp_outbox, order_seat_guests, password_resets).

**Why:** local test DBs don't reproduce this, so it slips through testing.

**How to apply:** every new migration table: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`. When testing locally, create the test DB with `COLLATE utf8mb4_unicode_ci` and replace the dump's `utf8mb4_0900_ai_ci` with `utf8mb4_unicode_ci` (not general_ci). Related: [[push-after-every-change]].
