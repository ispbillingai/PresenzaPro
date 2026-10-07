---
name: device-monitor-feature
description: Shop-device up/down monitoring — built in BOTH ristorante/order AND crm/bitrix (Devices + Network Areas + disconnection log + tech role)
metadata: 
  node_type: memory
  type: project
  originSessionId: addc1f52-6019-4fdd-bd94-a5780984c3f9
---

Built 2026-07-06. Live up/down status of shop devices. **NOW IN BOTH APPS** — user asked to move it to bitrix but keep the ristorante copy too. Both crons run every minute.

**crm/bitrix version** (f:\bitrix → /var/www/html/crm, as dashboard tabs):
- `migrations/015_devices.sql` — network_areas + devices + **device_events (disconnection log)**.
- `src/Devices/RouterOsApi.php` + `src/Devices/Monitor.php` (`\Glue\` namespaced; `Monitor::poll()` logs up/down transitions).
- `bin/poll-devices.php` (cron every min), `public/device-api.php` (status/log/poll/test + area CRUD).
- `views/devices.php` (status + disconnection log), `views/network_areas.php` (router CRUD, admin-only).
- Dashboard tabs `devices`+`network_areas`; new **`tech` role** (technical area) sees ONLY Devices; admin sees all. role_tech in Users page. Verified: tech blocked from network_areas + admin API actions.
- Router password in `network_areas.api_pass` DB column (set on server, not git).
- **Customer filter** on Devices page (dropdown by network area, shows when >1 area).
- **Editable devices**: admins add/rename/delete devices from Devices page; each MUST be associated with a router (Customer/Router dropdown, required when routers exist).
- **WhatsApp disconnection alerts** (migration 016): each network_area has `alert_phone`; Monitor::sendAlerts() WhatsApps that number on device down AND up transitions via existing TextMeBot gateway (already configured+enabled on server, sends from +393518931111). No alert storm — if the router itself is unreachable its devices are skipped, not marked down. Set per-customer number in Network Areas page. Live-tested working 2026-07-06.
- Routers on server as of 2026-07-06: Panificio Azzurro (192.168.200.15, has the 5 devices), Cisbu Mugnano, Ciscu S.Arpino (both empty). Device #1 is named "COMANDA" not "Order".

**ristorante/order version** (original, simpler — no disconnection log, no tech role):

**Why server-pulls-from-router:** the MikroTik's `/tool fetch` is broken (can't POST out — see [[panificio-azzurro-network]]). So the SERVER polls: it logs into the router's RouterOS API (port 8728, reachable over WireGuard) and pings each device.

**Pieces (all in f:\order):**
- `migrations/006_devices.sql` — `devices` table (name, ip, status, latency_ms, last_seen_at, last_checked_at), seeded with the 5 shop IPs.
- `migrations/007_network_areas.sql` — `network_areas` table (routers: host, api_port, api_user, api_pass, ping_count, active) + `devices.area_id`. Seeds the router "Panificio Azzurro" (192.168.200.15).
- `includes/device_monitor.php` — raw RouterOS API client (`RouterOsApi`) + `networkAreas()`, `connectToArea()`, `pollDevices()`. Loops over DB areas; each device pinged through its area's router; falls back to config/devices.php pre-migration.
- `bin/poll-devices.php` — CLI poller. **Cron on server: `* * * * *` (every minute).**
- `api/devices.php` — admin JSON status (GET) + on-demand poll (POST {poll:1}).
- `api/network-area-test.php` — admin "Test connection" to a router.
- `admin/devices.php` — live status page (auto-refresh 10s + "Check now").
- `admin/network_areas.php` — add/edit/delete routers. Edit with blank password keeps stored one.
- Nav links in `includes/header.php`; EN/IT keys in `lang/{en,it}.php`.

**Server-only secrets (NOT in git):** the router's API password lives in the `network_areas.api_pass` DB column, set to `<ROUTER_PASSWORD>` directly on the server (migration seeds it blank). config/devices.php also gitignored.

Deploy per [[crm-deploy-workflow]]: edit f:\order → push → `git pull` in /var/www/html/ristorante → `php migrate.php`.
