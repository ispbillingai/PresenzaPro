<?php
declare(strict_types=1);

function currentUser(): ?array
{
    static $cache = [];
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = fetchOne('SELECT * FROM users WHERE id = ? AND is_active = 1', [$id]);
        if ($cache[$id] === null) {
            unset($_SESSION['user_id']);
        }
    }
    return $cache[$id];
}

function requireLogin(): array
{
    $u = currentUser();
    if ($u === null) {
        if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            jsonOut(['ok' => false, 'error' => 'Sessione scaduta, accedi di nuovo.'], 401);
        }
        redirect('/login.php');
    }
    return $u;
}

function requireRole(string $role): array
{
    $u = requireLogin();
    if ($u['role'] !== $role) {
        redirect($u['role'] === 'admin' ? '/admin/' : '/employee/');
    }
    return $u;
}

function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    unset($_SESSION['csrf']);
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int)$user['id']]);
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
