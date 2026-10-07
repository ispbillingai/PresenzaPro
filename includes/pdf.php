<?php
/**
 * Minimal PDF writer (no dependencies): A4 pages, Helvetica / Helvetica-Bold text with
 * WinAnsi encoding (Italian accents OK), lines and filled rectangles. Coordinates are in
 * points from the top-left corner of the page.
 */
declare(strict_types=1);

class SimplePdf
{
    public const W = 595.28;
    public const H = 841.89;

    private array $pages = [];
    private string $cur = '';

    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667,
        611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833,
        556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    public function addPage(): void
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
        }
        $this->cur = '';
    }

    private function enc(string $s): string
    {
        $s = (string)@iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
    }

    public function textWidth(string $s, float $size): float
    {
        $w = 0;
        $s = (string)@iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        foreach (str_split($s) as $ch) {
            $o = ord($ch);
            $w += ($o >= 32 && $o <= 126) ? self::WIDTHS[$o - 32] : 556;
        }
        return $w * $size / 1000;
    }

    /** $align: 'L', 'R' or 'C' relative to $x (for R/C, $x is the right edge / centre). */
    public function text(float $x, float $y, string $s, float $size = 9, bool $bold = false, string $align = 'L', array $rgb = [0, 0, 0]): void
    {
        if ($s === '') {
            return;
        }
        if ($align === 'R') {
            $x -= $this->textWidth($s, $size);
        } elseif ($align === 'C') {
            $x -= $this->textWidth($s, $size) / 2;
        }
        $this->cur .= sprintf("BT %s rg /F%d %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $this->rgb($rgb), $bold ? 2 : 1, $size, $x, self::H - $y, $this->enc($s));
    }

    /** Truncate with an ellipsis to fit $maxWidth. */
    public function fit(string $s, float $size, float $maxWidth): string
    {
        if ($this->textWidth($s, $size) <= $maxWidth) {
            return $s;
        }
        while (mb_strlen($s) > 1 && $this->textWidth($s . '…', $size) > $maxWidth) {
            $s = mb_substr($s, 0, -1);
        }
        return $s . '…';
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $rgb = [0, 0, 0]): void
    {
        $this->cur .= sprintf("%s RG %.2f w %.2f %.2f m %.2f %.2f l S\n", $this->rgb($rgb), $width, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->cur .= sprintf("%s rg %.2f %.2f %.2f %.2f re f\n", $this->rgb($rgb), $x, self::H - $y - $h, $w, $h);
    }

    private function rgb(array $c): string
    {
        return sprintf('%.3f %.3f %.3f', $c[0] / 255, $c[1] / 255, $c[2] / 255);
    }

    public function output(string $title = ''): string
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
            $this->cur = '';
        }
        $objs = [];
        $objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $n = count($this->pages);
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (5 + $i * 2) . ' 0 R';
        }
        $objs[] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->pages as $i => $content) {
            $objs[] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, 6 + $i * 2);
            $objs[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        $info = sprintf('<< /Title (%s) /Producer (PresenzaPro) /CreationDate (D:%s) >>', $this->enc($title), date('YmdHis'));
        $objs[] = $info;

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }
        $out .= "trailer\n<< /Size " . (count($objs) + 1) . ' /Root 1 0 R /Info ' . count($objs) . " 0 R >>\n";
        $out .= "startxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }
}
