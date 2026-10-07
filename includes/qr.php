<?php
/**
 * QR images drawn on the server (PNG), for what goes out on WhatsApp: online
 * orders' pay QR, till customers' code, coupons. Needs the qrencode tool
 * (apt package; see CLAUDE.md); without it messages go without the image.
 */

/** The server can draw QR images (the qrencode tool is installed). */
function qrPngAvailable(): bool
{
    static $ok = null;
    return $ok ??= is_executable('/usr/bin/qrencode');
}

/** A QR of $text as PNG bytes, or null. */
function qrPng(string $text): ?string
{
    if (!qrPngAvailable()) return null;
    $png = shell_exec('/usr/bin/qrencode -t PNG -s 10 -m 3 -l M -o - ' . escapeshellarg($text));
    return is_string($png) && substr($png, 1, 3) === 'PNG' ? $png : null;
}
