---
name: no-wireguard-changes
description: User forbids modifying the existing WireGuard tunnel — use OpenVPN for remote access instead
metadata: 
  node_type: memory
  type: feedback
  originSessionId: addc1f52-6019-4fdd-bd94-a5780984c3f9
---

On 2026-07-06 the user said "stop" while I was diagnosing the WireGuard path and then instructed: "dont add any wireguard, only install openvpn". I reverted the AllowedIPs/route change I had made on the server.

**Why:** The WireGuard tunnel (server ↔ MikroTik, see [[panificio-azzurro-network]]) is production plumbing for the site; the user does not want it touched.

**How to apply:** Never edit /etc/wireguard/wg0.conf, `wg set`, or MikroTik WireGuard peers. Remote-access needs go through the OpenVPN server on crm.upgradesrls.com. Consequence: 192.168.100.x devices are not reachable from VPN clients — manage them by SSH-hopping through the MikroTik (192.168.200.15).
