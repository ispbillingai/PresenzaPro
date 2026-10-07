<?php
/**
 * Berkel scale PLU export.
 *
 * Builds the "anagrafica PLU" file for an Avery Berkel scale system (XMA),
 * from the menu items of the configured category. The file is fixed-length
 * ASCII text (CRLF), as XMA expects (see the Avery Berkel "Tracciati files
 * scambio dati" doc): numeric fields right-justified zero-filled, alpha
 * fields left-justified space-filled and ASCII-folded.
 *
 * The record layout we produce (agree it with the XMA import config):
 *   Reparto     2N   department (1-99)
 *   Plu         6N   menu item id
 *   Attivo      1N   0 = active, 1 = to delete
 *   Tipo plu    1N   0 = by weight, 1 = by pack, 2 = fixed price
 *   Prezzo      6N   price in cents
 *   Gruppo      4N   merchandise group (category id)
 *   Nome breve  32A  display name
 *
 * Config (settings.berkel): enabled, category_id, reparto, tipo_plu, filename.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/settings.php';
requireRole(['admin']);

$cfg       = (array) getSetting('berkel', []);
$categoryId = (int) ($cfg['category_id'] ?? 0);
$reparto    = max(1, min(99, (int) ($cfg['reparto'] ?? 1)));
$tipoPlu    = in_array((int) ($cfg['tipo_plu'] ?? 0), [0, 1, 2], true) ? (int) $cfg['tipo_plu'] : 0;
$filename   = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($cfg['filename'] ?? 'plu.txt')) ?: 'plu.txt';

if ($categoryId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Berkel: category not configured';
    exit;
}

/** Numeric field: right-justified, zero-filled, truncated to width (keeps low digits). */
$num = static function ($v, int $width): string {
    $s = (string) (int) $v;
    if (strlen($s) > $width) {
        $s = substr($s, -$width);
    }
    return str_pad($s, $width, '0', STR_PAD_LEFT);
};

/** Alpha field: ASCII-folded, left-justified, space-filled, truncated to width. */
$alpha = static function (string $s, int $width): string {
    $s = strtr($s, [
        'à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'í' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ù' => 'u', 'ú' => 'u',
        'À' => 'A', 'È' => 'E', 'É' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        '€' => 'E',
    ]);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($ascii !== false) {
        $s = $ascii;
    }
    $s = preg_replace('/[^\x20-\x7E]/', '', $s);
    if (strlen($s) > $width) {
        $s = substr($s, 0, $width);
    }
    return str_pad($s, $width, ' ', STR_PAD_RIGHT);
};

// All items of the category (active and inactive): an inactive one is exported
// as "to delete" so the scale drops it too.
$pdo  = getDBConnection();
$stmt = $pdo->prepare("SELECT id, name, base_price, active FROM menu_items WHERE category_id = ? ORDER BY sort_order, name");
$stmt->execute([$categoryId]);
$items = $stmt->fetchAll();

$lines = [];
foreach ($items as $it) {
    $cents = (int) round(((float) $it['base_price']) * 100);
    $lines[] = $num($reparto, 2)
             . $num($it['id'], 6)
             . $num((int) $it['active'] === 1 ? 0 : 1, 1)
             . $num($tipoPlu, 1)
             . $num($cents, 6)
             . $num($categoryId, 4)
             . $alpha((string) $it['name'], 32);
}
$body = implode("\r\n", $lines) . (empty($lines) ? '' : "\r\n");

header('Content-Type: text/plain; charset=us-ascii');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($body));
echo $body;
