<?php
/**
 * Device & Payment Integrations Config (EXAMPLE)
 * RestoPOS — mirrors the parking app's config so behaviour matches.
 *
 * Copy this file to config/devices.php and fill in real values for the till.
 * config/devices.php is git-ignored (it holds device credentials / IPs).
 *
 * ARCHITECTURE: the cashier PHP calls the local hardware SERVER-SIDE (curl),
 * reaching each device over TAILSCALE (we no longer use ngrok). Put each
 * device's Tailscale IP/hostname below. A `curl(28) timeout` means the device
 * isn't reachable over Tailscale (offline / wrong IP / Tailscale down).
 *
 * MONEY: amounts go to the devices as integer CENTS + ISO 4217 code; the POS DB
 * stays DECIMAL. Conversion via includes/devices.php (toCents / fromCents).
 */

return [
    // ---- Currency -------------------------------------------------------
    'currency' => [
        'code'     => 978,    // ISO 4217 numeric: 978 = EUR, 840 = USD
        'symbol'   => '€',
        'decimals' => 2,
    ],

    // ---- Cashmatic automated cash machine (HTTP REST) ------------------
    // "Start payment (cash)" button. Reached over Tailscale.
    'cashmatic' => [
        'enabled'    => true,
        'base_url'   => 'http://100.x.y.z:50301',   // Cashmatic REST over Tailscale
        'username'   => 'cashmatic',
        'password'   => 'admin',
        'verify_ssl' => false,
    ],

    // ---- Card terminal / POS client (RTS WebDoReMi -> Ingenico) -------
    // "Pay by card" button. base_url is the RTS WebDoremiposWS HTTP service
    // on the till LAN (reached over Tailscale); it wraps Protocol 17 to the
    // Ingenico terminal. terminal_name is the <terminal name="…"> from RTS.
    // Leave base_url empty to hide the "Pay by card" button.
    // mode 'p17' = no RTS PC: the server speaks Protocol 17 straight to the
    // terminal (ECR line set to TCP/IP). base_url = tcp://<terminal ip>:<port>,
    // terminal_name = terminal ID (8 digits, 00000000 = any), ecr_id = till ID.
    'pos' => [
        'enabled'         => true,
        'mode'            => 'rts',     // 'rts' | 'p17'
        'ecr_id'          => '00000001', // p17 only
        'base_url'        => 'http://100.x.y.z/WebDoremiposWS',  // RTS service (Tailscale)
        'terminal_name'   => 'Ingenico-XXXXXXXX',                // RTS terminal name
        'protocol_type'   => '0',       // 0 auto / 1 credit / 2 debit
        'connect_timeout' => 5,
        'read_timeout'    => 90,        // covers card tap + acquirer auth
    ],

    // ---- Dojo card terminal (Dojo Cloud API "Pay at Counter") --------
    // "Pay by Dojo" button. Unlike the Ingenico/RTS terminal above, this talks
    // to Dojo's CLOUD API (api.dojo.tech) — no local device, no Tailscale hop.
    // Get secret_key + terminal_id from the Dojo Developer Portal (NOT the
    // dashboard login). Use an sk_sandbox_ key to test, sk_prod_ to go live.
    // reseller_id / software_house_id are REQUIRED on terminal calls (sandbox:
    // reseller1 / softwareHouse1; production values come from Dojo). Leave secret_key or terminal_id empty to hide the
    // "Pay by Dojo" button.
    'dojo' => [
        'enabled'           => false,
        'base_url'          => 'https://api.dojo.tech',
        'secret_key'        => '',                 // sk_sandbox_… / sk_prod_…
        'terminal_id'       => '',                 // Dojo terminalId for this till
        'version'           => '2026-02-27',       // Dojo API version header
        'capture_mode'      => 'Auto',             // Auto = capture immediately
        'reseller_id'       => '',                 // sandbox: reseller1
        'software_house_id' => '',                 // sandbox: softwareHouse1
        'connect_timeout'   => 5,
        'read_timeout'      => 20,                 // per HTTP call; the tap itself is polled
        'poll_interval_ms'  => 1500,
        'verify_ssl'        => true,
    ],

    // ---- Epson fiscal printer (Registratore Telematico) ---------------
    // Drives the EFT-POS over Protocol 17 and prints the fiscal receipt via
    // its fpmate.cgi web service. Leave base_url empty to disable.
    // brand 'rch' = RCH PRINT! 3.0 RT instead: commands go to its service.cgi
    // web service; cash_payment / card_payment are the RT's payment numbers
    // (=T1 cash, =T4 electronic by default).
    'fiscal_printer' => [
        'enabled'    => true,
        'brand'      => 'epson',              // 'epson' | 'rch'
        'base_url'   => 'http://100.x.y.z',   // fiscal RT printer (Tailscale)
        'cash_payment' => 1,                  // RCH only
        'card_payment' => 4,                  // RCH only
        'operator'   => '1',
        'timeout_ms' => 35000,
        'auto_print' => true,
        'verify_ssl' => false,
    ],

    // ---- Cashier thermal printer (ESC/POS over raw TCP) ---------------
    // Prints the NON-FISCAL proforma bill (itemised, WITH prices + total) at
    // the cash desk when the cashier closes the order. The official fiscal
    // receipt is emitted separately by the Epson printer after payment.
    'cashier_printer' => [
        'enabled'  => true,
        'host'     => '100.x.y.z',   // cash-desk thermal printer (Tailscale)
        'port'     => 9100,
        'timeout'  => 5,
        'width'    => 32,            // 32 for 58mm paper, 48 for 80mm
        'codepage' => 2,
    ],

    // ---- Kitchen thermal printer (ESC/POS over raw TCP) ---------------
    // Prints a kitchen ticket (table number + dishes, NO prices) when an
    // order is sent to the kitchen. Reached server-side over Tailscale.
    // Set host empty / enabled false to disable kitchen printing.
    'kitchen_printer' => [
        'enabled'  => true,
        'host'     => '100.x.y.z',   // network thermal printer (Tailscale)
        'port'     => 9100,
        'timeout'  => 5,
        'width'    => 32,            // characters per line (58mm=32, 80mm=48)
        'codepage' => 2,            // 2 = CP850 (accented letters)
    ],

    // ---- QR codes (digital receipt) -----------------------------------
    'qr' => [
        'enabled'      => true,
        'receipt_base' => 'https://your-domain.example/cashier/receipt.php',
    ],

];
