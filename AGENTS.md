# PresenzaPro — handoff notes

Repo `ispbillingai/PresenzaPro` (**public**), folder `F:\PresenzaPro`. Started 2026-10-07 as a copy of
the Focacciami POS (pub), then wiped the same day: PresenzaPro is a **new, unrelated app** (employee
time tracking with GPS-bound clock-in/out). Nothing is shared with pub except the server.

## What the app is
PHP 8.3 + MariaDB, no framework, Italian UI. See README.md for the structure and the clocking rules.
- Admin: dipendenti, sedi (lat/lng + raggio, Leaflet map picker), assegnazioni, timbrature, report ore, impostazioni.
- Dipendente: `employee/` clock page (watchPosition → `api/clock.php`), monthly history.
- Every clock attempt is stored in `clockings` (accepted / rejected / voided, source gps or manual, with reason and GPS data).
- "Badge reader" features (2026-10-07, migration 001): shifts + weekly schedule per employee, absences
  (ferie/permesso/malattia/altro, whole-day or hours), company holidays + computed Italian national ones,
  manual clockings and voiding by admin, monthly summary in `admin/reports.php` computed by
  `includes/attendance.php::attendanceReport()`. Per-employee clocking webhook (migration 004 replaced the earlier personal
  login link, which the user did NOT want): `users.webhook_url` + `webhook_enabled`, global
  `settings.webhooks_enabled`, GET fired after the API response in `api/clock.php` via
  `includes/webhook.php`, logged in `webhook_log`, test button in the employee form.
- Migration 002 (2026-10-07): leave requests (employee → admin approval creates the absence), alerts cron
  `bin/check-alerts.php` (server crontab every 5 min; email via mail(), WhatsApp via TextMeBot key in
  settings), leave/permit entitlements + balances page, payroll CSV export, clocked breaks
  (shift `break_mode`, clocking types break_start/break_end), per-date schedule overrides.
  Test scripts used during development live in Codex's scratchpad, not in the repo: they seed fixtures
  into the local XAMPP DB `presenzapro_test` and include the pages with `$_SESSION['user_id']` set.

## Where it runs
- Server: 217.160.131.242 (IONOS Ubuntu 24.04, Apache 2.4, PHP 8.3, MariaDB 10.11), SSH as root.
  Credentials and the plink one-liner are in Codex's local memory `pub-server.md`
  (`C:\Users\magom\.Codex\projects\f--PresenzaPro\memory\`), never in git.
- App folder: `/var/www/html/presenzapro` (git clone, branch `main`).
- Domain: https://presenzapro.upgradesrls.com (DNS pointed and Let's Encrypt certificate installed on
  2026-10-07, auto-renew via certbot). Vhosts: `/etc/apache2/sites-available/presenzapro.conf` (port 80,
  redirects to HTTPS) and `presenzapro-le-ssl.conf` (443). HTTPS is required for browser geolocation.
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
