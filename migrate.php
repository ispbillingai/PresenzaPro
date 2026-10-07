<?php
/**
 * Database Migration Runner
 * RestoPOS
 *
 * Applies versioned SQL migration files from the /migrations folder so you can
 * safely add new tables/columns/rows without manually running SQL on the server.
 *
 * How it works:
 *   - Each change lives in its own file in /migrations, named NNN_description.sql
 *     (e.g. 001_create_parking.sql, 002_add_vehicle_to_orders.sql).
 *   - This runner records every applied file in a `migrations` table, so running
 *     it again only applies files that haven't run yet. It is safe to re-run.
 *
 * Usage:
 *   CLI (recommended):   php migrate.php
 *   CLI dry-run:         php migrate.php --dry-run
 *   Web (one-off):       https://yoursite/migrate.php?key=YOUR_SECRET
 *
 * Security:
 *   Web access requires a secret key. Set MIGRATE_KEY below (or as an env var
 *   MIGRATE_KEY). CLI runs do not require the key.
 *   After you finish migrating in production, consider deleting/blocking this
 *   file from the web (it can alter your database).
 */

require_once __DIR__ . '/config/database.php';

// ----------------------------------------------------------------------------
// Config
// ----------------------------------------------------------------------------
$MIGRATE_KEY   = getenv('MIGRATE_KEY') ?: 'change-this-secret';
$MIGRATIONS_DIR = __DIR__ . '/migrations';

$isCli   = (php_sapi_name() === 'cli');
$dryRun  = $isCli && in_array('--dry-run', $argv ?? [], true);

// ----------------------------------------------------------------------------
// Output helper (plain text in CLI, simple HTML in browser)
// ----------------------------------------------------------------------------
function out($msg, $type = 'info') {
    global $isCli;
    if ($isCli) {
        $prefix = ['ok' => '[OK]  ', 'skip' => '[--]  ', 'err' => '[ERR] ', 'info' => '      '][$type] ?? '';
        echo $prefix . $msg . PHP_EOL;
    } else {
        $color = ['ok' => '#16a34a', 'skip' => '#6b7280', 'err' => '#dc2626', 'info' => '#111827'][$type] ?? '#111827';
        echo '<div style="font-family:monospace;color:' . $color . '">' . htmlspecialchars($msg) . '</div>';
        @ob_flush(); @flush();
    }
}

// ----------------------------------------------------------------------------
// Access control for web requests
// ----------------------------------------------------------------------------
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2 style="font-family:sans-serif">RestoPOS — Database Migrations</h2>';
    $key = $_GET['key'] ?? '';
    if (!hash_equals($MIGRATE_KEY, (string) $key)) {
        http_response_code(403);
        out('Access denied. Append ?key=YOUR_SECRET to the URL (set MIGRATE_KEY first).', 'err');
        exit;
    }
}

// ----------------------------------------------------------------------------
// Run
// ----------------------------------------------------------------------------
try {
    $pdo = getDBConnection();
} catch (Throwable $e) {
    out('Could not connect to the database: ' . $e->getMessage(), 'err');
    exit(1);
}

// 1. Ensure the tracking table exists.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        filename    VARCHAR(255) NOT NULL UNIQUE,
        applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// 2. Which migrations have already run?
$applied = $pdo->query("SELECT filename FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

// 3. Find migration files, sorted by name (so NNN_ prefixes run in order).
if (!is_dir($MIGRATIONS_DIR)) {
    out('No migrations folder found at: ' . $MIGRATIONS_DIR, 'err');
    out('Create it and add files like 001_my_change.sql', 'info');
    exit(1);
}
$files = glob($MIGRATIONS_DIR . '/*.sql');
sort($files, SORT_STRING);

if (!$files) {
    out('No .sql files in /migrations — nothing to do.', 'info');
    exit(0);
}

// 4. Apply each new migration inside its own transaction.
$ran = 0;
foreach ($files as $path) {
    $name = basename($path);

    if (isset($applied[$name])) {
        out($name . ' (already applied)', 'skip');
        continue;
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        out($name . ' is empty — skipping', 'skip');
        continue;
    }

    if ($dryRun) {
        out($name . ' WOULD run (dry-run)', 'info');
        continue;
    }

    try {
        $pdo->beginTransaction();
        // Run the file. PDO can execute multiple statements separated by ;
        $pdo->exec($sql);
        $stmt = $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)");
        $stmt->execute([$name]);
        // DDL (CREATE/ALTER/DROP TABLE) implicitly commits in MySQL, which ends
        // the transaction. Only commit if one is still open — otherwise PDO
        // throws "There is no active transaction". DML-only migrations stay
        // fully transactional.
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        out($name . ' applied', 'ok');
        $ran++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        out($name . ' FAILED: ' . $e->getMessage(), 'err');
        out('Migration stopped. Fix the SQL and run again — already-applied files are skipped.', 'err');
        exit(1);
    }
}

out('', 'info');
out($dryRun ? 'Dry-run complete.' : ("Done. {$ran} new migration(s) applied."), 'ok');
