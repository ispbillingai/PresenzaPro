---
name: crm-feature-requests-2026-07
description: "Customer's 7 CRM feature requests (2026-07-06) — what was built in bitrix, what was already there, what's out of scope"
metadata: 
  node_type: memory
  type: project
  originSessionId: addc1f52-6019-4fdd-bd94-a5780984c3f9
---

Customer sent 7 requests on 2026-07-06 for the crm/bitrix app ([[ristorante-is-order-repo]] explains crm vs order). Status:

1. **Notify agent on lead assign** — ALREADY EXISTED (`Automation::agentAssigned` enqueues `agent_new_assignment` WhatsApp+email on every `Leads::assign()`). Verified template renders. No build needed.
2. **Google Ads stats link** — OUT OF SCOPE, user said "leave this alone" (Angelo's task).
3. **"IN CONTACT" pipeline column** — DONE. Renamed lead stage Contacted→"In Contact"/"In contatto" (stg_CONTACTED lang + seed + migration 017). Same code CONTACTED.
4. **Monitor private-area access** — DONE. migration 018: contacts.portal_access_count + portal_last_access_at + portal_access_log table. `Account::recordAccess()` on both portal logins (magic-link + password). Shown on lead detail + a count pill.
5. **Referrers as PARTNERS** (big) — DONE. migration 019: partners, leads.referred_by_partner_id, partner_accruals. `src/Partner/Partners.php`. Referral link request.php?ref=CODE tags leads. Won referred deal → pending accrual = commission_pct% of deal.amount (NOTE: deals value column is `amount` not `value`). Admin approves→pays. Partner logs into `public/partner.php` (own session crm_partner) to see referrals+status+accrual totals. Admin `partners` dashboard tab (admin-only; agents/tech never see it). Commission rules confirmed by user: % of won deal value, personal referral link, admin-approves accruals.
6. **Query CRM by agent** — DONE. Agents page: each agent drawer lists their assigned leads with stage+status (uses `Leads::all(500, $agentId)`).
7. **Discarded pipeline never populated** — FIXED. `Leads::byStage()` only returned status='open', so leads moved to lost stage (JUNK, status='junk') vanished. Now includes 'junk' so the Discarded column shows them.

All shipped via [[crm-deploy-workflow]]: edit f:\bitrix, push, git pull /var/www/html/crm, php migrate.php. Migrations 017-019 applied on server. All tested live. dashboard.password master login used for admin curl tests.
