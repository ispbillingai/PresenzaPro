---
name: parking-reference-app
description: Location and architecture of the parking app we port payment/printing features from
metadata: 
  node_type: memory
  type: project
  originSessionId: d741810f-5fbd-4f7a-a79a-de8d79d022af
---

The working reference app to copy payment/printing behaviour from lives at
`F:\xampp\htdocs\parking` (a git repo; has src/, public/, config/config.php).
The older partial copy at `F:\parking` only has the Cashmatic cash flow — do NOT
use it; use the xampp one.

We are porting these into the order POS (f:\order): Cashmatic cash machine,
Ingenico card payment, Epson fiscal receipt printer, and QR e-receipts — making
the order cashier/printing behave like the parking app's `public/cashier-pay.php`
kiosk (buttons: Start payment (cash) / Pay by card / Cancel). User confirmed:
replace the cashier UI with the kiosk-style UI; currency is EUR (configurable).

Key device source files in the parking app:
- public/cashier-pay.php — kiosk payment page
- public/api/card-pay-cashier.php — card payment endpoint
- public/api/cashmatic-{start,poll,finish,cancel}.php — Cashmatic flow
- src/Fiscal/Client.php, src/Fiscal/Receipt.php — Epson RT printer
- src/Pos/Client.php — card/POS client
- src/Cashmatic/Client.php, SessionClient.php

See [[device-payment-architecture]].
