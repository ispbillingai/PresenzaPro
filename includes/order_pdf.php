<?php
/**
 * PDF of an order (what the guests had that day), for the admin Customers
 * page. A table's order includes its seat bills (the whole meal, dishes marked
 * with their seat); a seat bill is just that seat. Built with FPDF
 * (includes/lib/fpdf, free licence), text in Windows-1252 for the € sign.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/lib/fpdf/fpdf.php';

/** UTF-8 → the PDF core fonts' encoding (keeps €, accents). */
function pdfText(?string $s): string
{
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', (string) $s);
    return $out === false ? preg_replace('/[^\x20-\x7e]/', '?', (string) $s) : $out;
}

function pdfMoney($v): string
{
    return pdfText('€ ' . number_format((float) $v, 2, ',', '.'));
}

/** @return string the PDF bytes, or '' when the order doesn't exist */
function renderOrderPdf(int $orderId): string
{
    $pdo   = getDBConnection();
    $order = getOrderById($orderId);
    if (!$order) return '';

    // The whole meal for a table's order: its own dishes + its seat bills'.
    $orderIds = [$orderId];
    if (empty($order['parent_order_id'])) {
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE parent_order_id = ? AND status <> 'cancelled'");
        $stmt->execute([$orderId]);
        $orderIds = array_merge($orderIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
    $in = implode(',', array_fill(0, count($orderIds), '?'));

    $stmt = $pdo->prepare("
        SELECT oi.quantity, oi.unit_price, oi.total_price, oi.seat, oi.notes, mi.name, mc.name AS category
        FROM order_items oi JOIN menu_items mi ON mi.id = oi.menu_item_id JOIN menu_categories mc ON mc.id = mi.category_id
        WHERE oi.order_id IN ($in) AND oi.status <> 'cancelled'
        ORDER BY oi.seat IS NULL, oi.seat, oi.created_at, oi.id
    ");
    $stmt->execute($orderIds);
    $items = $stmt->fetchAll();

    foreach ($orderIds as $id) calculateOrderTotals($id);
    $stmt = $pdo->prepare("SELECT SUM(number_of_people), SUM(number_of_people * cover_charge_per_person), SUM(subtotal), SUM(discount_amount), SUM(total) FROM orders WHERE id IN ($in)");
    $stmt->execute($orderIds);
    [$people, $covers, $subtotal, $discount, $total] = $stmt->fetch(PDO::FETCH_NUM);

    $stmt = $pdo->prepare("SELECT method, amount, created_at FROM payments WHERE order_id IN ($in) ORDER BY created_at");
    $stmt->execute($orderIds);
    $payments = $stmt->fetchAll();

    $ws    = $pdo->query("SELECT name FROM workspaces LIMIT 1")->fetch();
    $brand = $ws['name'] ?? 'Focacciami';

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle(pdfText(t('pdf_order_title') . ' ' . $order['order_number']));
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    $pdf->SetMargins(16, 16, 16);

    // Header: the logo (or the name)
    if ($logoFile = brandLogoPrintFile()) {
        $pdf->Image($logoFile, 16, 12, 52);
        $pdf->SetY(12 + 52 * 300 / 713 + 2);
    } else {
        $pdf->SetTextColor(232, 89, 12);
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->Cell(0, 9, pdfText($brand), 0, 1);
    }
    // Address and contacts, when set in Settings.
    require_once __DIR__ . '/restaurant.php';
    $w       = restaurantInfo();
    $contact = implode(' · ', array_filter([restaurantAddressLine(), !empty($w['phone']) ? t('rs_phone_short') . ' ' . $w['phone'] : '',
                                            preg_replace('~^https?://(www\.)?~i', '', rtrim((string) ($w['website'] ?? ''), '/'))]));
    if ($contact !== '') {
        $pdf->SetTextColor(107, 114, 128);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(0, 5, pdfText($contact), 0, 1);
    }
    $pdf->SetTextColor(31, 41, 55);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell(0, 8, pdfText(t('pdf_order_title') . ' ' . $order['order_number']), 0, 1);
    $pdf->SetDrawColor(229, 231, 235);
    $pdf->Line(16, $pdf->GetY() + 1, 194, $pdf->GetY() + 1);
    $pdf->Ln(4);

    // Order + guest details, two columns
    $when = date('d/m/Y H:i', strtotime($order['opened_at'] ?: $order['created_at']));
    $left = [
        t('pdf_date')   => $when . ($order['closed_at'] ? ' → ' . date('H:i', strtotime($order['closed_at'])) : ''),
        t('table')      => $order['table_number'] . ' · ' . $order['room_name'],
        t('guests')     => (string) (int) $people,
        t('waiter')     => $order['waiter_name'],
        t('status')     => statusLabel($order['status']),
    ];
    $right = [];
    if (!empty($order['customer_name']))  $right[t('cust_name')]  = $order['customer_name'];
    if (!empty($order['customer_city']))  $right[t('cust_city')]  = $order['customer_city'];
    if (!empty($order['customer_phone'])) $right[t('cust_phone')] = $order['customer_phone'];

    $y0 = $pdf->GetY();
    foreach ([[16, $left], [108, $right]] as [$x, $rows]) {
        $pdf->SetXY($x, $y0);
        foreach ($rows as $k => $v) {
            $pdf->SetX($x);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(107, 114, 128);
            $pdf->Cell(30, 6, pdfText($k), 0, 0);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->Cell(56, 6, pdfText($v), 0, 1);
        }
    }
    $pdf->SetY(max($y0 + 6 * count($left), $y0 + 6 * count($right)) + 5);

    // Dishes
    $w = [14, 90, 22, 26, 26];
    $pdf->SetFillColor(243, 244, 246);
    $pdf->SetFont('Helvetica', 'B', 9);
    foreach ([[t('pdf_qty'), 'C'], [t('pdf_dish'), 'L'], [t('seat'), 'C'], [t('pdf_unit'), 'R'], [t('total'), 'R']] as $i => [$h, $a]) {
        $pdf->Cell($w[$i], 7, pdfText($h), 0, 0, $a, true);
    }
    $pdf->Ln();
    $pdf->SetFont('Helvetica', '', 10);
    foreach ($items as $it) {
        $pdf->Cell($w[0], 7, (string) (int) $it['quantity'], 'B', 0, 'C');
        $name = $it['name'] . ($it['notes'] ? ' (' . $it['notes'] . ')' : '');
        if ($pdf->GetStringWidth(pdfText($name)) > $w[1] - 2) {
            while (mb_strlen($name) > 3 && $pdf->GetStringWidth(pdfText($name . '…')) > $w[1] - 2) $name = mb_substr($name, 0, -1);
            $name .= '…';
        }
        $pdf->Cell($w[1], 7, pdfText($name), 'B', 0);
        $pdf->Cell($w[2], 7, $it['seat'] ? (string) (int) $it['seat'] : '-', 'B', 0, 'C');
        $pdf->Cell($w[3], 7, pdfMoney($it['unit_price']), 'B', 0, 'R');
        $pdf->Cell($w[4], 7, pdfMoney($it['total_price']), 'B', 1, 'R');
    }
    if (!$items) {
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(0, 8, pdfText(t('pdf_no_dishes')), 0, 1);
        $pdf->SetTextColor(31, 41, 55);
    }

    // Totals
    $pdf->Ln(3);
    $line = function (string $label, string $value, bool $bold = false) use ($pdf) {
        $pdf->SetFont('Helvetica', $bold ? 'B' : '', $bold ? 12 : 10);
        $pdf->Cell(152, 7, pdfText($label), 0, 0, 'R');
        $pdf->Cell(26, 7, $value, 0, 1, 'R');
    };
    if ((float) $covers > 0) $line(t('cover') . ' (' . (int) $people . ')', pdfMoney($covers));
    $line(t('subtotal'), pdfMoney($subtotal));
    if ((float) $discount > 0) $line(t('discount'), pdfText('-') . pdfMoney($discount));
    $line(t('total'), pdfMoney($total), true);

    // Payments
    if ($payments) {
        $pdf->Ln(4);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 7, pdfText(t('pdf_payments')), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        foreach ($payments as $p) {
            $pdf->Cell(60, 6, pdfText(date('d/m/Y H:i', strtotime($p['created_at']))), 0, 0);
            $pdf->Cell(60, 6, pdfText(ucfirst(str_replace('_', ' ', $p['method']))), 0, 0);
            $pdf->Cell(58, 6, pdfMoney($p['amount']), 0, 1, 'R');
        }
    }

    // Footer note
    $pdf->SetY(-24);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(156, 163, 175);
    $pdf->Cell(0, 5, pdfText(t('pdf_note') . ' · ' . date('d/m/Y H:i')), 0, 0, 'C');

    return $pdf->Output('S');
}
