<?php
/**
 * Countries with their international dialling prefix, for the guest's phone
 * number on an order. Italy first (the default), the rest by name.
 */

/** [ISO code => [dial prefix, Italian name, English name]] */
const PHONE_COUNTRIES = [
    'IT' => ['+39', 'Italia', 'Italy'],
    'AL' => ['+355', 'Albania', 'Albania'],
    'DZ' => ['+213', 'Algeria', 'Algeria'],
    'AD' => ['+376', 'Andorra', 'Andorra'],
    'SA' => ['+966', 'Arabia Saudita', 'Saudi Arabia'],
    'AR' => ['+54', 'Argentina', 'Argentina'],
    'AU' => ['+61', 'Australia', 'Australia'],
    'AT' => ['+43', 'Austria', 'Austria'],
    'BE' => ['+32', 'Belgio', 'Belgium'],
    'BY' => ['+375', 'Bielorussia', 'Belarus'],
    'BA' => ['+387', 'Bosnia ed Erzegovina', 'Bosnia and Herzegovina'],
    'BR' => ['+55', 'Brasile', 'Brazil'],
    'BG' => ['+359', 'Bulgaria', 'Bulgaria'],
    'CA' => ['+1', 'Canada', 'Canada'],
    'CL' => ['+56', 'Cile', 'Chile'],
    'CN' => ['+86', 'Cina', 'China'],
    'CY' => ['+357', 'Cipro', 'Cyprus'],
    'CO' => ['+57', 'Colombia', 'Colombia'],
    'KR' => ['+82', 'Corea del Sud', 'South Korea'],
    'HR' => ['+385', 'Croazia', 'Croatia'],
    'DK' => ['+45', 'Danimarca', 'Denmark'],
    'EC' => ['+593', 'Ecuador', 'Ecuador'],
    'EG' => ['+20', 'Egitto', 'Egypt'],
    'AE' => ['+971', 'Emirati Arabi Uniti', 'United Arab Emirates'],
    'EE' => ['+372', 'Estonia', 'Estonia'],
    'PH' => ['+63', 'Filippine', 'Philippines'],
    'FI' => ['+358', 'Finlandia', 'Finland'],
    'FR' => ['+33', 'Francia', 'France'],
    'DE' => ['+49', 'Germania', 'Germany'],
    'JP' => ['+81', 'Giappone', 'Japan'],
    'GR' => ['+30', 'Grecia', 'Greece'],
    'IN' => ['+91', 'India', 'India'],
    'ID' => ['+62', 'Indonesia', 'Indonesia'],
    'IE' => ['+353', 'Irlanda', 'Ireland'],
    'IS' => ['+354', 'Islanda', 'Iceland'],
    'IL' => ['+972', 'Israele', 'Israel'],
    'KE' => ['+254', 'Kenya', 'Kenya'],
    'XK' => ['+383', 'Kosovo', 'Kosovo'],
    'LV' => ['+371', 'Lettonia', 'Latvia'],
    'LB' => ['+961', 'Libano', 'Lebanon'],
    'LI' => ['+423', 'Liechtenstein', 'Liechtenstein'],
    'LT' => ['+370', 'Lituania', 'Lithuania'],
    'LU' => ['+352', 'Lussemburgo', 'Luxembourg'],
    'MK' => ['+389', 'Macedonia del Nord', 'North Macedonia'],
    'MT' => ['+356', 'Malta', 'Malta'],
    'MA' => ['+212', 'Marocco', 'Morocco'],
    'MX' => ['+52', 'Messico', 'Mexico'],
    'MD' => ['+373', 'Moldavia', 'Moldova'],
    'MC' => ['+377', 'Monaco', 'Monaco'],
    'ME' => ['+382', 'Montenegro', 'Montenegro'],
    'NG' => ['+234', 'Nigeria', 'Nigeria'],
    'NO' => ['+47', 'Norvegia', 'Norway'],
    'NZ' => ['+64', 'Nuova Zelanda', 'New Zealand'],
    'NL' => ['+31', 'Paesi Bassi', 'Netherlands'],
    'PK' => ['+92', 'Pakistan', 'Pakistan'],
    'PE' => ['+51', 'Perù', 'Peru'],
    'PL' => ['+48', 'Polonia', 'Poland'],
    'PT' => ['+351', 'Portogallo', 'Portugal'],
    'QA' => ['+974', 'Qatar', 'Qatar'],
    'GB' => ['+44', 'Regno Unito', 'United Kingdom'],
    'CZ' => ['+420', 'Repubblica Ceca', 'Czech Republic'],
    'DO' => ['+1', 'Repubblica Dominicana', 'Dominican Republic'],
    'RO' => ['+40', 'Romania', 'Romania'],
    'RU' => ['+7', 'Russia', 'Russia'],
    'SM' => ['+378', 'San Marino', 'San Marino'],
    'SN' => ['+221', 'Senegal', 'Senegal'],
    'RS' => ['+381', 'Serbia', 'Serbia'],
    'SG' => ['+65', 'Singapore', 'Singapore'],
    'SK' => ['+421', 'Slovacchia', 'Slovakia'],
    'SI' => ['+386', 'Slovenia', 'Slovenia'],
    'ES' => ['+34', 'Spagna', 'Spain'],
    'LK' => ['+94', 'Sri Lanka', 'Sri Lanka'],
    'US' => ['+1', 'Stati Uniti', 'United States'],
    'ZA' => ['+27', 'Sudafrica', 'South Africa'],
    'SE' => ['+46', 'Svezia', 'Sweden'],
    'CH' => ['+41', 'Svizzera', 'Switzerland'],
    'TH' => ['+66', 'Thailandia', 'Thailand'],
    'TN' => ['+216', 'Tunisia', 'Tunisia'],
    'TR' => ['+90', 'Turchia', 'Turkey'],
    'UA' => ['+380', 'Ucraina', 'Ukraine'],
    'HU' => ['+36', 'Ungheria', 'Hungary'],
    'UY' => ['+598', 'Uruguay', 'Uruguay'],
    'VA' => ['+379', 'Vaticano', 'Vatican City'],
    'VE' => ['+58', 'Venezuela', 'Venezuela'],
    'VN' => ['+84', 'Vietnam', 'Vietnam'],
];

/** Flag emoji for an ISO country code (🇮🇹 for IT). */
function countryFlag(string $iso): string
{
    $iso = strtoupper($iso);
    if (!preg_match('/^[A-Z]{2}$/', $iso)) return '';
    return mb_chr(0x1F1E6 + ord($iso[0]) - 65) . mb_chr(0x1F1E6 + ord($iso[1]) - 65);
}

/** Country list for a select, Italy first then by name in the current language. */
function phoneCountryOptions(): array
{
    $it   = currentLang() === 'it';
    $list = [];
    foreach (PHONE_COUNTRIES as $iso => [$dial, $nameIt, $nameEn]) {
        $list[] = ['iso' => $iso, 'dial' => $dial, 'name' => $it ? $nameIt : $nameEn, 'flag' => countryFlag($iso)];
    }
    $first = array_shift($list);
    usort($list, fn($a, $b) => strcoll($a['name'], $b['name']));
    return array_merge([$first], $list);
}

/**
 * Full international number from the country + the number as typed:
 * separators dropped; outside Italy/San Marino/Vatican a leading trunk 0 is
 * dropped too (020 7946 0000 in the UK → +44 20 7946 0000).
 * Returns null when it isn't a plausible phone number.
 */
function internationalPhone(string $iso, string $number): ?string
{
    $iso = strtoupper($iso);
    if (!isset(PHONE_COUNTRIES[$iso])) return null;
    $digits = preg_replace('/\D/', '', $number);
    if (!in_array($iso, ['IT', 'SM', 'VA'], true)) {
        $digits = preg_replace('/^0/', '', $digits);
    }
    if (strlen($digits) < 4 || strlen($digits) > 14) return null;
    return PHONE_COUNTRIES[$iso][0] . $digits;
}

/**
 * A mobile that can receive a WhatsApp code: an Italian one starts with 3 and
 * has 9-10 digits (a number typed short — "338 3265" — is refused at once, not
 * by WhatsApp minutes later); elsewhere at least 7 digits. The 00000000xx
 * numbers are the staff's test numbers (never real phones).
 */
function mobileLooksValid(string $iso, string $e164): bool
{
    $national = preg_replace('/\D/', '', nationalPhone($iso, $e164));
    if (str_starts_with($national, '00000000')) return true;
    if (strtoupper($iso) === 'IT') return (bool) preg_match('/^3\d{8,9}$/', $national);
    return strlen($national) >= 7;
}

/** The number without its country prefix (to show it back in the form). */
function nationalPhone(string $iso, ?string $e164): string
{
    $dial = PHONE_COUNTRIES[strtoupper($iso)][0] ?? '';
    $e164 = (string) $e164;
    return ($dial !== '' && str_starts_with($e164, $dial)) ? substr($e164, strlen($dial)) : ltrim($e164, '+');
}
