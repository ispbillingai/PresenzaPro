<?php
/**
 * Photos of menu products (Admin > Menu, Admin > Menu cassa).
 */

/**
 * Save an uploaded menu image to assets/uploads/menu and return its web path,
 * or null if no valid image was uploaded. Accepts JPG/PNG/WebP/GIF up to 5MB.
 */
function saveMenuImage(string $field): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['size'] > 5 * 1024 * 1024) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extMap[$mime])) {
        return null;
    }
    $dir = __DIR__ . '/../assets/uploads/menu';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $name = 'item_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return null;
    }
    return '/assets/uploads/menu/' . $name;
}
