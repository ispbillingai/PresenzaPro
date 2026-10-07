---
name: customer-requests-2026-09
description: "Upgrade Srls WhatsApp asks of 22-23 Sep 2026 for ristorante — Dojo + Glovo built, what's still open (kiosk/SeeYouFood vision, CRM calendar, Cashmatic lead phone bug)"
metadata:
  node_type: memory
  type: project
  originSessionId: 8d5f0ef8-eb4f-41d3-9325-ce4f10be9435
  modified: 2026-09-28T10:16:02.342Z
---

Customer (Upgrade Srls / Antonio) chat 22-23 Sep 2026. Status as of 2026-09-23:

- **Dojo Pay at Counter** — built + deployed (241b39a). Sandbox key received 2026-09-28 and saved on the server (Admin > Payment Gateways; headers softwareHouse1/reseller1; terminal = VCMUPGRSCN0 success simulator). Sandbox has 6 virtual terminals: S=success, D=decline, C=customer cancel, T=never answers, SIS/DIS=signature. Full cashier flow verified. The physical PAX A920 (TID 49604663, serial 0824018925) was linked by Dojo to the sandbox account on 2026-09-29 as tm_sandbox_6aba2dd3f767775c0180bc10; the app's saved terminal now points at it (sandbox = no real charges, any card). Production go-live still needs a prod key + real software-house/reseller ids, and refunds (Dojo go-live checklist requires them). Refunds not built. Also joined tables shipped (af106b3).
- **Glovo** — orders side built + deployed (2320d98, 5c9af82): webhook api/glovo-webhook.php, admin/glovo.php, includes/glovo.php. Waiting on Glovo credentials (partner.integrationseu@glovoapp.com: token, store id, staging test store). NOT built yet: menu/stock/price sync (Glovo Stock & Price API: /webhook/stores/{id}/menu, bulk updates). Glovo product id defaults to our menu_items.id. Fiscal receipt for Glovo orders deliberately not auto-emitted — open question for the customer.
- **"SeeYouFood"-style vision** (customer wants to replace their current vendor): menu builder with limited-time offers + graphics, sync to till/waiter app, customer self-order kiosk/tablets (like McDonald's/KFC). Not started — "start with a skeleton".
- **Technician calendar** (per-technician day/week/month, customer search + "+ New customer" modal) — this is the CRM (f:\bitrix), not ristorante. Not touched.
- **Cashmatic-imported lead missing phone number** — CRM (bitrix) bug, not touched.
- Emmegm Group Srl (clothing shop, Napoli) wants a quote for tills — sales, not code.

Testing on the live server: test Glovo orders via Admin > Glovo "Send a test order" (never calls Glovo). getSetting() caches per process — in CLI tests write settings before first read. Related: [[push-after-every-change]], [[ristorante-is-order-repo]].
