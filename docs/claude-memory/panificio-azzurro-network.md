---
name: panificio-azzurro-network
description: "Server + MikroTik SSH credentials, WireGuard topology, and device IPs for the PANIFICIO AZZURRO site"
metadata: 
  node_type: memory
  type: reference
  originSessionId: addc1f52-6019-4fdd-bd94-a5780984c3f9
---

**Cloud server**: `crm.upgradesrls.com` (217.160.56.237, IONOS, Ubuntu 24.04). SSH `root` / `<ROOT_PASSWORD>` (rotated 2026-09-12; old password dead — source of truth: f:\bitrix project memory server-access.md). ICMP blocked from outside; ports 22/80/443 open. Runs Apache (CRM on 80/443), MariaDB (localhost:3306), Mosquitto MQTT (1883), WireGuard `wg0` = 192.168.200.3.

Plink one-liner (host key pinned):
`& F:\PuttyGen\plink.exe -batch -ssh root@crm.upgradesrls.com -pw "<ROOT_PASSWORD>" -hostkey "SHA256:F05uGYF90fefk2bxZZKuCGa2/sb7drtk7ElfbqnJtXQ" "<cmd>"`

**MikroTik router** (site "PANIFICIO AZZURRO", L009UiGS-2HaxD, RouterOS 7.x): 192.168.200.15, SSH `admin` / `<ROUTER_PASSWORD>`. Host key `SHA256:tnVHPQ946qo5jIajxJwhGDl1gbOHDXCugxHB1q5HsBE`. Not reachable from the internet â€” hop through the server with plink `-proxycmd "... -nc 192.168.200.15:22"`.

**WireGuard** (pre-existing â€” DO NOT MODIFY, see [[no-wireguard-changes]]): server 192.168.200.3 â†” MikroTik peer (endpoint 217.160.12.45:19744). AllowedIPs server side: 192.168.200.0/24, 192.168.52.0/24. Config: /etc/wireguard/wg0.conf, service wg-quick@wg0. MikroTik side allowed-address is 192.168.200.0/22; its WAN was 192.168.1.166 (DHCP) on 2026-07-06 â€” same subnet as user's home LAN.

**OpenVPN server** (installed 2026-07-06): UDP 1194 on the server, subnet 10.8.0.0/24, split-tunnel pushing routes 192.168.200.0/24 + 192.168.52.0/24. Config /etc/openvpn/server/server.conf, PKI in /etc/openvpn/easy-rsa/pki (EC, CA=crm-vpn-ca, client cert magome-pc), service openvpn-server@server. NAT: 10.8.0.0/24 â†’ wg0 masquerade (iptables-persistent). Client file: C:\Users\magom\Desktop\crm-upgradesrls-vpn.ovpn. IONOS panel firewall only allows TCP 22/80/443 inbound â€” UDP 1194 must be opened in the IONOS Cloud Panel or clients can't connect. VPN clients reach 192.168.200.x (routers) but NOT shop LAN 192.168.100.x (would need the WG AllowedIPs change the user declined).

**Shop LAN 192.168.100.0/24** (behind MikroTik):
- .10 Order server
- .11 Fiscal printer (Epson RT, see [[device-payment-architecture]])
- .12 Cashier PC (MAC 40:62:31:39:49:97)
- .13 Cashmatic
- .14 POS (WiFi, ~50ms)

