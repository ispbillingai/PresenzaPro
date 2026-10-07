# Database Migrations

Versioned SQL changes applied by [`../migrate.php`](../migrate.php). This lets us
add new tables, columns, or rows without manually running SQL on the production
server — and without forgetting which changes have already been applied.

## How to add a change

1. Create a new file here named with the next number:
   ```
   002_add_vehicle_columns.sql
   003_create_parking_tables.sql
   ```
   Always increase the number — files run in ascending order.

2. Write your SQL. Prefer idempotent statements so accidental re-runs are safe:
   ```sql
   ALTER TABLE orders ADD COLUMN IF NOT EXISTS vehicle_plate VARCHAR(20) NULL;

   CREATE TABLE IF NOT EXISTS parking_slots (
       id INT AUTO_INCREMENT PRIMARY KEY,
       code VARCHAR(20) NOT NULL UNIQUE,
       status ENUM('free','occupied','reserved') NOT NULL DEFAULT 'free'
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
   ```

3. Commit & push (this repo pushes on every change), then on the server:
   ```bash
   cd /var/www/html/order
   git pull origin main
   php migrate.php          # applies only the new files
   ```

## Running

| Where | Command |
|-------|---------|
| Server CLI (recommended) | `php migrate.php` |
| Preview without applying  | `php migrate.php --dry-run` |
| Browser (one-off)         | `https://yoursite/migrate.php?key=YOUR_SECRET` |

Each applied file is recorded in a `migrations` table, so `migrate.php` is safe
to run repeatedly — it skips anything already applied.

## Web access secret

Web runs require a key. Set it via the `MIGRATE_KEY` environment variable, or
edit the default in [`../migrate.php`](../migrate.php). For production, prefer
running from the CLI and blocking this endpoint from the web.
