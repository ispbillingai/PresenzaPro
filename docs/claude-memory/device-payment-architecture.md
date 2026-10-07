---
name: device-payment-architecture
description: How card/cash/fiscal payment hardware is wired in the parking app (and to be reused in order POS)
metadata: 
  node_type: memory
  type: reference
  originSessionId: d741810f-5fbd-4f7a-a79a-de8d79d022af
---

Payment/printing device architecture used by the parking app (and being ported
to the order POS):

- **Card payment runs THROUGH the Epson fiscal printer.** The Epson RT
  (Registratore Telematico) drives the EFT-POS / Ingenico card terminal over its
  own **Protocol 17** wiring. One call to the printer's `fpmate.cgi` web service
  (`authorizeSales`) charges the card AND prints the fiscal receipt. There is no
  separate Ingenico TCP daemon in the final design. User's words: "pass the
  amount to an app, it charges the card via the Ingenico locally."
- **Cashmatic** (automated cash machine) is a separate local REST device.
- Architecture is **server-side PHP → device via curl** (not pure browser-direct).
  Devices are reached over **Tailscale** (they moved OFF ngrok — do not use ngrok).
  Device addresses are set in `config` (Tailscale IPs/hostnames):
  - `fiscal_printer.base_url` = Epson RT printer
  - `cashmatic.base_url` = Cashmatic REST
  - `pos.host` / `pos.port` = the card terminal / POS client
- **`curl(28): Connection timeout after 5000ms` on "Pay by card"** = the PHP host
  can't reach the device's Tailscale address. Fix: device online + correct
  Tailscale IP in config + Tailscale up on the PHP host.

Currency is handled in integer **cents** + ISO 4217 when talking to devices
(EUR/978); the order POS DB stays DECIMAL. See [[parking-reference-app]] and
[[push-after-every-change]].
