<?php
/**
 * Create (or reset the password of) an admin user.
 *   php bin/create-admin.php <username> <password> ["Full name"]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$username = trim((string)($argv[1] ?? ''));
$password = (string)($argv[2] ?? '');
$fullName = trim((string)($argv[3] ?? 'Amministratore'));

if ($username === '' || strlen($password) < 8) {
    fwrite(STDERR, "Uso: php bin/create-admin.php <username> <password di almeno 8 caratteri> [\"Nome completo\"]\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
if ($existing) {
    q('UPDATE users SET password_hash = ?, role = "admin", is_active = 1 WHERE id = ?', [$hash, (int)$existing['id']]);
    echo "Password aggiornata per l'admin '$username' (id {$existing['id']}).\n";
} else {
    q('INSERT INTO users (role, username, password_hash, full_name) VALUES ("admin", ?, ?, ?)', [$username, $hash, $fullName]);
    echo "Admin '$username' creato (id " . db()->lastInsertId() . ").\n";
}
