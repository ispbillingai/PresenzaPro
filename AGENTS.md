# PresenzaPro — handoff notes

This folder (`F:\PresenzaPro`, repo `ispbillingai/PresenzaPro`, **public**) is a copy of `F:\pub`
(repo `ispbillingai/pub`, the Focacciami POS), copied on 2026-10-07 at commit `3436519`
("Loyalty: coupon rules by spend, plus a \"single purchase\" period"). Work on **PresenzaPro** continues here.
It is a separate app: changes made here do not reach pub/Focacciami, and pub changes do not reach this repo.
Other copies of pub on the same server: `F:\progetto` (made the same day), `F:\numeratore` (Eliminacode)
and `F:\chiamata`.

## What the app is (inherited from pub)
PHP + MariaDB restaurant POS: admin, cashier, waiter, kitchen, guest ordering (QR menu),
fiscal printing, card payments (Epson RT protocol 17 / Dojo), Glovo orders, "Clienti online"
(`online.php`). The PresenzaPro-specific features are built on top of this.

## Where it runs
- Server: the same machine as pub, 217.160.131.242 (IONOS Ubuntu 24.04, Apache 2.4, PHP 8.3,
  MariaDB 10.11), SSH as root. Credentials and the plink one-liner are in Claude's local memory
  `pub-server.md` (`C:\Users\magom\.claude\projects\f--PresenzaPro\memory\`), never in git.
- App folder: `/var/www/html/presenzapro` (git clone of `ispbillingai/PresenzaPro`, branch `main`).
- Domain: `presenzapro.upgradesrls.com`, vhost `/etc/apache2/sites-available/presenzapro.conf` (port 80).
  **DNS not pointed yet** (2026-10-07). Until the A record points to 217.160.131.242, test on the
  server with `curl -H "Host: presenzapro.upgradesrls.com" http://127.0.0.1/`. Once DNS points, run
  `certbot --apache -d presenzapro.upgradesrls.com --redirect` to add HTTPS.
- DB: `presenzapro`, user `presenzapro` (password only in the server's `config/database.php`). Seeded from the
  pub DB on 2026-10-07 (menu, rooms, tables, staff users, workspaces). Orders, customers,
  WhatsApp/TextMeBot, Glovo, payment-gateway, Cashmatic and printer settings were removed.
- Logs: `/var/log/apache2/presenzapro.upgradesrls.com-error.log` (and `-access.log`).
- `config/devices.php` on the server is the example file with every device **disabled**, so this app
  never touches the shop printer, POS or Cashmatic that pub uses. Enable devices only when asked.

## Workflow (do this after EVERY change, without being asked)
1. Edit locally in `F:\PresenzaPro`, `git commit`, `git push origin main`.
2. On the server, via plink:
   `cd /var/www/html/presenzapro && git pull origin main && php migrate.php`
3. Wait ~3 s (opcache revalidate_freq=2), then test live:
   - `php -l` each changed PHP file on the server;
   - `curl -s -o /dev/null -w '%{http_code}' -H "Host: presenzapro.upgradesrls.com" http://127.0.0.1/login.php`
     (or `https://presenzapro.upgradesrls.com/...` once DNS and the certificate are in place);
   - render changed pages with a CLI script that sets `$_SESSION['user_id']`;
   - `tail /var/log/apache2/presenzapro.upgradesrls.com-error.log`: no new errors.
4. Report the commit hash and the test result.

## Rules
- Deploy this repo **only** to `/var/www/html/presenzapro`. Never pull it into `/var/www/html/pub`
  (Focacciami, live), `/var/www/html/progetto`, `/var/www/html/eliminacode`, `/var/www/html/chiamata`
  or ristorante.
- Never hand-edit tracked files on the server. `config/database.php` and `config/devices.php` are
  gitignored and server-only.
- The repo is public: never commit passwords, API keys or tokens.
- New tables need `COLLATE utf8mb4_unicode_ci`.
- Users are never deleted, only disabled or enabled.
- Never change the WireGuard tunnel on this server (pub uses it to reach the shop's printer).
- Local setup: copy `config/database.example.php` to `config/database.php` (and `devices.example.php`),
  import `database_schema.sql`, then run `php migrate.php`.
- Older project notes (from pub/order) are in [docs/claude-memory/](docs/claude-memory/).
