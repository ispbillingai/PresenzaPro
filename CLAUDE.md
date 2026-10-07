# PresenzaPro — handoff notes

Repo `ispbillingai/PresenzaPro` (**public**), folder `F:\PresenzaPro`. Started 2026-10-07 as a copy of
the Focacciami POS (pub), then wiped the same day: PresenzaPro is a **new, unrelated app** (employee
time tracking with GPS-bound clock-in/out). Nothing is shared with pub except the server.

## What the app is
PHP 8.3 + MariaDB, no framework, Italian UI. See README.md for the structure and the clocking rules.
- Admin: dipendenti, sedi (lat/lng + raggio, Leaflet map picker), assegnazioni, timbrature, report ore, impostazioni.
- Dipendente: `employee/` clock page (watchPosition → `api/clock.php`), monthly history.
- Every clock attempt is stored in `clockings` (accepted or rejected, with reason and GPS data).

## Where it runs
- Server: 217.160.131.242 (IONOS Ubuntu 24.04, Apache 2.4, PHP 8.3, MariaDB 10.11), SSH as root.
  Credentials and the plink one-liner are in Claude's local memory `pub-server.md`
  (`C:\Users\magom\.claude\projects\f--PresenzaPro\memory\`), never in git.
- App folder: `/var/www/html/presenzapro` (git clone, branch `main`).
- Domain: `presenzapro.upgradesrls.com`, vhost `/etc/apache2/sites-available/presenzapro.conf` (port 80).
  **DNS not pointed yet** (2026-10-07). Until then test with
  `curl -H "Host: presenzapro.upgradesrls.com" http://127.0.0.1/`. When the A record points to the server:
  `certbot --apache -d presenzapro.upgradesrls.com --redirect`. **HTTPS is required** for browser
  geolocation, so the clock page only works on phones after the certificate is in place.
- DB: `presenzapro`, user `presenzapro` (password only in the server's `config/database.php`).
- Logs: `/var/log/apache2/presenzapro.upgradesrls.com-error.log`.
- Admin user: created with `php bin/create-admin.php <user> <password> "Nome"` on the server.

## Workflow (after EVERY change, without being asked)
1. Edit locally, `git commit`, `git push origin main`.
2. On the server: `cd /var/www/html/presenzapro && git pull origin main && php migrate.php`.
3. Wait ~3 s (opcache), then: `php -l` the changed files, curl `/login.php` for a 200, render changed
   pages with a CLI script that sets `$_SESSION['user_id']`, tail the error log.
4. Report the commit hash and the test result.

## Rules
- Deploy **only** to `/var/www/html/presenzapro`. Never touch `/var/www/html/pub`, `progetto`, `eliminacode`, `chiamata`.
- Never hand-edit tracked files on the server; `config/database.php` is gitignored and server-only.
- Public repo: never commit passwords, API keys or tokens.
- New tables: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`. Schema changes go in `migrations/NNN_name.sql`.
- Users are never deleted, only disabled (`is_active`).
- Never change the WireGuard tunnel on this server.
