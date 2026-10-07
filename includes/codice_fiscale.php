<?php
/**
 * Reading an Italian codice fiscale (typed, or scanned from the barcode of the
 * tessera sanitaria): date of birth, sex and place of birth come out of it;
 * name and surname can't (only three letters of each are in it).
 */

const CF_MONTHS   = 'ABCDEHLMPRST';   // January … December
const CF_OMOCODIA = 'LMNPQRSTUV';     // letters standing for 0-9 when two people share a code

/** The 16th character (control) of the first 15. */
function codiceFiscaleCheckChar(string $first15): string
{
    static $odd = [1, 0, 5, 7, 9, 13, 15, 17, 19, 21, 2, 4, 18, 20, 11, 3, 6, 8, 12, 14, 16, 10, 22, 25, 24, 23];
    $sum = 0;
    for ($i = 0; $i < 15; $i++) {
        $c = $first15[$i];
        $v = ctype_digit($c) ? (int) $c : ord($c) - 65;   // 0-9 and A-Z share the same values
        $sum += ($i % 2 === 0) ? $odd[$v] : $v;           // 1st, 3rd, … are the "odd" positions
    }
    return chr(65 + $sum % 26);
}

/** The shape of a codice fiscale (omocodia allowed), check character aside. */
function codiceFiscaleShapeOk(string $cf): bool
{
    $n = '[0-9' . CF_OMOCODIA . ']';
    return (bool) preg_match('/^[A-Z]{6}' . $n . '{2}[' . CF_MONTHS . ']' . $n . '{2}[A-Z]' . $n . '{3}[A-Z]$/', $cf);
}

/**
 * A valid codice fiscale from what was typed or scanned, or null. A scanner
 * set to a QWERTZ keyboard layout swaps every Y with Z: if the check
 * character fails, that swapped reading is tried too.
 */
function codiceFiscaleNormalize(string $raw): ?string
{
    $cf = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
    if (strlen($cf) !== 16) return null;
    $ok = fn($c) => codiceFiscaleShapeOk($c) && codiceFiscaleCheckChar(substr($c, 0, 15)) === $c[15];
    if ($ok($cf)) return $cf;
    $swapped = strtr($cf, 'YZ', 'ZY');
    return $swapped !== $cf && $ok($swapped) ? $swapped : null;
}

/**
 * What a codice fiscale says: ['cf', 'sex' (M|F), 'birth_date' (Y-m-d),
 * 'place' (comune or country, '' if unknown), 'province' ('EE' abroad),
 * 'foreign' (bool), 'place_code'], or null if it isn't a valid one.
 */
function codiceFiscaleDecode(string $raw): ?array
{
    $cf = codiceFiscaleNormalize($raw);
    if ($cf === null) return null;
    $digits = fn(string $s) => strtr($s, CF_OMOCODIA, '0123456789');
    $yy    = (int) $digits(substr($cf, 6, 2));
    $month = strpos(CF_MONTHS, $cf[8]) + 1;
    $day   = (int) $digits(substr($cf, 9, 2));
    $sex   = $day > 40 ? 'F' : 'M';
    if ($day > 40) $day -= 40;
    $year  = 2000 + $yy <= (int) date('Y') ? 2000 + $yy : 1900 + $yy;
    if (!checkdate($month, $day, $year)) return null;
    $birth = sprintf('%04d-%02d-%02d', $year, $month, $day);
    if ($birth > date('Y-m-d')) $birth = sprintf('%04d-%02d-%02d', $year - 100, $month, $day);

    $code  = $cf[11] . $digits(substr($cf, 12, 3));
    $place = codiceFiscalePlace($code, $birth);
    return [
        'cf' => $cf, 'sex' => $sex, 'birth_date' => $birth, 'place_code' => $code,
        'place' => $place[0] ?? '', 'province' => $place[1] ?? '', 'foreign' => $code[0] === 'Z',
    ];
}

/** [name, province] of a codice catastale on a date (the place it meant then), or null. */
function codiceFiscalePlace(string $code, string $onDate): ?array
{
    static $data = null;
    $data ??= require __DIR__ . '/data/belfiore.php';
    $list = $data[$code] ?? null;
    if (!$list) return null;
    foreach ($list as $p) {
        if ($p[2] <= $onDate && ($p[3] === '' || $onDate <= $p[3])) return [$p[0], $p[1]];
    }
    $last = end($list);   // born before/after the dates we know: the latest name
    return [$last[0], $last[1]];
}

/** "Napoli (NA)" / "Francia" for showing. */
function codiceFiscalePlaceLabel(array $d): string
{
    if ($d['place'] === '') return $d['place_code'];
    return $d['foreign'] || $d['province'] === '' ? $d['place'] : $d['place'] . ' (' . $d['province'] . ')';
}
