---
name: crm-deploy-workflow
description: "How to deploy changes to the CRM (bitrix) panel — edit locally, push, git pull on server; never edit server directly"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: addc1f52-6019-4fdd-bd94-a5780984c3f9
---

On 2026-07-06 the user said: "always do changes locally here and push and then git pull in server, just dont do changes directly on server."

**Why:** Editing files directly on the server (as I did for the Devices feature) collides with the user's own `git stash` / `git pull` cycle — my untracked files survived but my tracked-file edits landed in a stash and had to be popped back. Direct edits also never reach the GitHub repo, so the next `git pull` can clobber them.

**How to apply:** The CRM panel is the `bitrix` repo (github.com/ispbillingai/bitrix), served at `/var/www/html/crm` on crm.upgradesrls.com — see [[panificio-azzurro-network]]. It is NOT currently cloned on this machine (only `f:\order` = the separate `order` repo is here). To do CRM work properly: clone github.com/ispbillingai/bitrix locally, edit + commit + push there, then `git pull` on the server. Do NOT hand-edit files on the server.

Note: config/config.php is gitignored (holds secrets like `device_secret`) — that one legitimately lives only on the server and is edited there.

Related: the order app follows [[push-after-every-change]].
