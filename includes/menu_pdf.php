<?php
/**
 * The whole menu as a PDF (the guests' "download the menu" button): active
 * categories and dishes with description and price, the restaurant's name and
 * contacts on top. FPDF core fonts, text in Windows-1252 for the € sign.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/restaurant.php';
require_once __DIR__ . '/whatsapp_guest.php'; // restaurantName(), tIn()
require_once __DIR__ . '/order_pdf.php'; // pdfText(), pdfMoney(), FPDF

/** Public address of a page of this site (for links sent on WhatsApp). */
function publicUrl(string $path): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . ltrim($path, '/');
}

/** The menu to browse, in a language ('it' / 'en'). */
function menuViewUrl(string $lang): string
{
    return publicUrl('menu.php?lang=' . ($lang === 'it' ? 'it' : 'en'));
}

/** The menu as PDF; $download = save it instead of opening it. */
function menuPdfUrl(string $lang, bool $download = false): string
{
    return publicUrl('menu-pdf.php?lang=' . ($lang === 'it' ? 'it' : 'en') . ($download ? '&dl=1' : ''));
}

/** A WhatsApp link that opens a message with the menu, to forward to the others at the table. */
function menuShareUrl(string $lang): string
{
    return 'https://wa.me/?text=' . rawurlencode(tIn($lang === 'it' ? 'it' : 'en', 'menu_share_text', [
        'restaurant' => restaurantName(), 'url' => menuViewUrl($lang),
    ]));
}

/** Short form of menuShareUrl() for messages (redirects to it). */
function menuShareShortUrl(string $lang): string
{
    return publicUrl('share-menu.php?lang=' . ($lang === 'it' ? 'it' : 'en'));
}

function renderMenuPdf(string $lang): string
{
    $pdo   = getDBConnection();
    $items = $pdo->query("
        SELECT mi.name, mi.description, mi.base_price, mc.id AS cat_id, mc.name AS category
        FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
        WHERE mi.active = 1 AND mc.active = 1 AND mc.till_only = 0 AND mc.online_only = 0
        ORDER BY mc.sort_order, mc.name, mi.sort_order, mi.name
    ")->fetchAll();
    $byCat = [];
    foreach ($items as $it) $byCat[$it['cat_id']]['name'] = $it['category'];
    foreach ($items as $it) $byCat[$it['cat_id']]['items'][] = $it;

    $w   = restaurantInfo();
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle(pdfText(tIn($lang, 'menu_pdf_title') . ' - ' . restaurantName()));
    $pdf->SetMargins(18, 16, 18);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage();

    // Header: the logo (or the name), contacts.
    if ($logoFile = brandLogoPrintFile()) {
        $pdf->Image($logoFile, (210 - 76) / 2, 12, 76);
        $pdf->SetY(12 + 76 * 300 / 713 + 2);
    } else {
        $pdf->SetTextColor(232, 89, 12);
        $pdf->SetFont('Helvetica', 'B', 22);
        $pdf->Cell(0, 11, pdfText(restaurantName()), 0, 1, 'C');
    }
    $contact = implode(' · ', array_filter([restaurantAddressLine(), !empty($w['phone']) ? tIn($lang, 'rs_phone_short') . ' ' . $w['phone'] : '',
                                            preg_replace('~^https?://(www\.)?~i', '', rtrim((string) ($w['website'] ?? ''), '/'))]));
    if ($contact !== '') {
        $pdf->SetTextColor(107, 114, 128);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(0, 5, pdfText($contact), 0, 1, 'C');
    }
    $pdf->SetTextColor(31, 41, 55);
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Ln(3);
    $pdf->Cell(0, 8, pdfText(mb_strtoupper(tIn($lang, 'menu_pdf_title'))), 0, 1, 'C');
    $pdf->Ln(2);

    if (!$byCat) {
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(0, 10, pdfText(tIn($lang, 'menu_pdf_empty')), 0, 1, 'C');
    }
    $width = $pdf->GetPageWidth() - 36;
    foreach ($byCat as $cat) {
        if ($pdf->GetY() > 250) $pdf->AddPage();          // a category title never alone at the bottom
        $pdf->Ln(3);
        $pdf->SetTextColor(232, 89, 12);
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->Cell(0, 8, pdfText($cat['name']), 0, 1);
        $pdf->SetDrawColor(232, 89, 12);
        $pdf->Line(18, $pdf->GetY(), 18 + $width, $pdf->GetY());
        $pdf->Ln(2);
        foreach ($cat['items'] as $it) {
            if ($pdf->GetY() > 270) $pdf->AddPage();
            $pdf->SetTextColor(31, 41, 55);
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->Cell($width - 30, 6, pdfText($it['name']), 0, 0);
            $pdf->Cell(30, 6, pdfMoney($it['base_price']), 0, 1, 'R');
            if (trim((string) $it['description']) !== '') {
                $pdf->SetTextColor(107, 114, 128);
                $pdf->SetFont('Helvetica', 'I', 9);
                $pdf->MultiCell($width - 30, 4.5, pdfText(trim($it['description'])), 0, 'L');
            }
            $pdf->Ln(1.5);
        }
    }
    return $pdf->Output('S');
}
