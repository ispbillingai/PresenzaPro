---
name: test-mode-virtual-payment
description: "ristorante has a temporary \"Pagamento virtuale\" till button (Settings > Modalità test) to remove when the client finishes testing"
metadata:
  node_type: memory
  type: project
  originSessionId: d9854651-e1f6-4195-a7b0-c032f8a647a5
  modified: 2026-09-30T12:31:44.955Z
---

Added 2026-09-30 at the client's request while they test: cashier/payment.php "Pagamento virtuale" button → api/payments.php action `virtual_payment` (payment method 'test', migration 023, no fiscal receipt). Toggle: setting `test_payments` (default ON), card #testmode in admin/settings.php; helper testPaymentsEnabled() in includes/settings.php.

**Why:** client said "poi lo togliamo quando termino la fase di test".

**How to apply:** when they say testing is over, turn it off or remove the button/action/card (keep the 'test' enum value so old rows stay valid). Test payments count as revenue in reports until the data is reset — see [[reset-orders-customers]].
