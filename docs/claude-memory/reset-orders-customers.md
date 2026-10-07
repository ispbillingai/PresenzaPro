---
name: reset-orders-customers
description: "cancella ordini e clienti/utenti" on ristorante = truncate orders + customer data only, never staff users; no DB backup needed while testing
metadata:
  type: feedback
---

When the user asks to delete/reset "ordini e clienti" (sometimes says "utenti" but means guests) on the live ristorante DB: truncate order_item_modifications, kitchen_tickets, order_items, payments, order_seat_guests, table_requests, whatsapp_outbox, notifications, coupons, marketing_consents, marketing_consent_events, marketing_optouts, orders, activity_log, then free tables_restaurant (status free, current_order_id NULL). Keep menu, rooms, tables, settings and the staff `users` table.

**Why:** they are testing repeatedly; confirmed 2026-09-30 "il personale non lo devi togliere" and "puoi anche non salvare il database" (no mysqldump backup needed during testing).

**How to apply:** run it directly via plink (script like scratchpad reset3.sh), no clarifying question, no backup unless asked. Related: [[users-never-deleted]], [[push-after-every-change]].
