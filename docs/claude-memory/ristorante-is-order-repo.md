---
name: ristorante-is-order-repo
description: "The app we work on is \"ristorante\" on the server = the order repo = local f:\\order; the crm panel is a separate app, leave it alone"
metadata: 
  node_type: memory
  type: project
  originSessionId: addc1f52-6019-4fdd-bd94-a5780984c3f9
  modified: 2026-09-28T10:36:30.752Z
---

The restaurant app is deployed at **/var/www/html/ristorante** on crm.upgradesrls.com and served from its own Apache vhost at **https://ristorante.upgradesrls.com** (NOT under crm.upgradesrls.com — that host is the separate CRM). Its git remote is **github.com/ispbillingai/order.git** — the SAME repo as the local working copy at **f:\order**. They track the same commits.

After a `git pull` on the server, run `php migrate.php` in /var/www/html/ristorante to apply any new migrations/*.sql (the runner skips ones already recorded in the `migrations` table).

Careful when killing test processes over SSH: `pkill -f <pattern>` also matches the SSH command line itself and silently kills your own shell. Kill by port (`fuser -k 9101/tcp`) instead.

Three separate PHP apps live under /var/www/html on that server, easy to confuse:
- **ristorante/** → repo `order` → THIS is what we work on (local mirror = f:\order). Restaurant ordering: admin, cashier, kitchen, waiter, menu, tills/printers.
- **crm/** → repo `bitrix` → a different app (leads/deals CRM). DO NOT touch unless explicitly asked. On 2026-07-06 I mistakenly built a device-monitor feature into crm; the user corrected me and I fully reverted it (git clean, dropped DB table, removed config secret + vhost).
- **parking/** → the reference app for payment/printing ports, see [[parking-reference-app]].

**Workflow** ([[crm-deploy-workflow]] rule applies here too): edit locally in f:\order, commit, push to origin/main, then I deploy it myself (`git pull` + `php migrate.php` in /var/www/html/ristorante) and test live, per [[push-after-every-change]]. Do NOT edit the server files directly. config/config.php is gitignored (server-only secrets).

Device-monitor feature (pending, if resumed): the MikroTik's `/tool fetch` is broken for ALL destinations (empty error, no packets — even to Google), so the router cannot POST out. Working design = server pulls status via RouterOS API (port 8728, open over WireGuard) on a cron and writes to DB. See [[panificio-azzurro-network]] for device IPs.
