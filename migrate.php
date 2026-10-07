<?php
/**
 * PresenzaPro - migration runner.
 *   php migrate.php
 * Applies database_schema.sql on an empty database, then every migrations/*.sql
 * not yet recorded in the `migrations` table, in filename order.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/includes/bootstrap.php';

$pdo = db();
$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
    name VARCHAR(120) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$applied = array_column($pdo->query('SELECT name FROM migrations')->fetchAll(), 'name');

function runSqlFile(PDO $pdo, string $file): void
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Cannot read $file");
    }
    // Strip full-line comments, then split on ';' at end of statement.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
}

$files = [];
$hasUsers = (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetch();
if (!$hasUsers && !in_array('000_schema', $applied, true)) {
    $files['000_schema'] = __DIR__ . '/database_schema.sql';
} elseif (!in_array('000_schema', $applied, true)) {
    $pdo->prepare('INSERT INTO migrations (name) VALUES (?)')->execute(['000_schema']);
}
foreach (glob(__DIR__ . '/migrations/*.sql') ?: [] as $f) {
    $files[basename($f, '.sql')] = $f;
}
ksort($files);

$count = 0;
foreach ($files as $name => $file) {
    if (in_array($name, $applied, true)) {
        continue;
    }
    echo "Applying $name ... ";
    runSqlFile($pdo, $file);
    $pdo->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
    echo "ok\n";
    $count++;
}
echo $count === 0 ? "Nothing to apply.\n" : "$count migration(s) applied.\n";
