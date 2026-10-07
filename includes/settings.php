<?php
/**
 * Key/value application settings (DB-backed), used for admin-editable config
 * such as the printer setup. Values are stored as JSON.
 */

require_once __DIR__ . '/../config/database.php';

/** Read a setting. Returns $default if missing or the table doesn't exist yet. */
function getSetting(string $key, $default = null)
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $pdo  = getDBConnection();
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) {
            return $cache[$key] = $default;
        }
        $decoded = json_decode((string) $row['setting_value'], true);
        return $cache[$key] = ($decoded === null && $row['setting_value'] !== 'null') ? $default : $decoded;
    } catch (Throwable $e) {
        return $default;
    }
}

/** Test mode: the till shows "Virtual payment" (closes a bill without money). */
function testPaymentsEnabled(): bool
{
    return (bool) getSetting('test_payments', true);
}

/** Write a setting (stored as JSON). Returns false on failure. */
function setSetting(string $key, $value): bool
{
    try {
        $pdo  = getDBConnection();
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        return $stmt->execute([$key, $json]);
    } catch (Throwable $e) {
        error_log('setSetting(' . $key . ') failed: ' . $e->getMessage());
        return false;
    }
}
