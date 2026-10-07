<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function csrfCheck(?string $token = null): void
{
    $token = $token ?? ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!is_string($token) || $token === '' || !hash_equals(csrfToken(), $token)) {
        if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            jsonOut(['ok' => false, 'error' => 'Sessione non valida, ricarica la pagina.'], 403);
        }
        http_response_code(403);
        exit('Token CSRF non valido. Ricarica la pagina.');
    }
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function takeFlashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fmtDate(?string $dt, string $fmt = 'd/m/Y H:i'): string
{
    if (!$dt) {
        return '';
    }
    $t = strtotime($dt);
    return $t ? date($fmt, $t) : '';
}

/** Minutes -> "7h 35m". */
function fmtMinutes(int $m): string
{
    return intdiv($m, 60) . 'h ' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT) . 'm';
}

function clientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}
