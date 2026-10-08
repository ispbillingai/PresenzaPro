<?php
/**
 * PresenzaPro - common bootstrap: config, DB, session, helpers.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_NAME', 'PresenzaPro');
define('APP_VERSION', '0.3.1');

date_default_timezone_set('Europe/Rome');
mb_internal_encoding('UTF-8');

$configFile = APP_ROOT . '/config/database.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Manca config/database.php (copia config/database.example.php).');
}
require_once $configFile;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/geo.php';

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('presenzapro');
    session_start();
}
