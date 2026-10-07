---
name: push-after-every-change
description: ALWAYS deploy myself after every change in f:\order — commit, push, git pull + migrate on the server in /var/www/html/ristorante, then test live. Never leave deploying to the user.
metadata:
  node_type: memory
  type: feedback
  originSessionId: d741810f-5fbd-4f7a-a79a-de8d79d022af
  modified: 2026-09-28T17:21:48.946Z
---

After ANY change to this project — even a small one — I commit, `git push origin main`, then DEPLOY IT MYSELF and TEST it live, without being asked and without asking first. The user has standing-authorized this (2026-09-23: "any local commit we do here we push, deploy and test"; repeated 2026-09-28: "always deploy after every change").

**Why:** On 2026-09-28 I pushed the bill-by-seat feature and then told the user to run `git pull` themselves, with the wrong path (/var/www/html/order). They had to correct me. The app lives in a DIFFERENT folder on the server: /var/www/html/ristorante — not "order", even though the repo is called order.

**How to apply:** Edit → commit → `git push origin main` → deploy with plink (credentials in [[panificio-azzurro-network]]): `cd /var/www/html/ristorante && git pull origin main && php migrate.php` → test live: php -l the changed files, render the changed pages via a CLI script that sets `$_SESSION['user_id']` (DB queries through the app's config/database.php — root mysql CLI has no password access), curl https://ristorante.upgradesrls.com, check /var/log/apache2/ristorante.upgradesrls.com-error.log. Complex scripts: write to a file and run `plink ... -m file.sh` (inline PowerShell here-strings get mangled). Wait ~3 s after `git pull` before live-testing: opcache revalidate_freq=2, so a request in the first seconds can mix new and old files and log a bogus "Call to undefined function" fatal (seen 2026-09-28). Report commit hash + test result. Never hand-edit server files ([[crm-deploy-workflow]]); config/config.php is server-only. Server DB is MariaDB, so `ADD COLUMN IF NOT EXISTS` works there (the MySQL 8 order.sql dump in the repo is old/not the live server).
