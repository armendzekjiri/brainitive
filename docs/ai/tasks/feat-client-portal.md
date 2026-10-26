# Client portal: deliverables, client feedback, version-specific approvals (BRAI-73)

**Branch:** feat/client-portal (from release/v1.42.0)
**Status:** done (branch; merged by the lead)
**Started:** 2026-10-26
**Board card:** BRAI-73 (board item 175) — contract: [release-v1.42.0.md](./release-v1.42.0.md), "Ticket 2"

## Problem / goal
Give clients a focused view of what the team explicitly shares with them (a published doc version,
a frozen plan snapshot), let them approve / request changes on that exact version and comment,
without workspace membership and without exposing anything else in the project.

## Approach
- One portal per project (`client_portals`), off by default, public path `/client/{slug}`.
- Clients are `PortalIdentity` rows (foundation guest sign-in) with a membership per portal
  (`client_portal_members`: invited_by, expires_at, revoked_at). Access = `portal.identity` + an
  active membership of THIS portal; disabled / unknown portal and other portals' ids → 404;
  revoked / expired → 403 (friendly page).
- Deliverables are **frozen at publish** (`client_deliverables.content`): a page's latest
  `page_versions` row rendered with `PageContentConverter::pageContentToHtml`, cleaned by
  `ClientPortal\DeliverableHtml` (internal links → plain text, mentions → plain text, sticky notes and
  whiteboard embeds dropped, then `SafeHtml::clean`); or a plan snapshot (rows without hidden
  columns and without `itemId`). Never drafts — only published docs.
- Publishing the same page / plan again supersedes the previous current deliverable
  (`superseded_at`, `superseded_by_id`); its decision stays as history; the new one is awaiting review.
  The same page version twice = idempotent.
- Decisions (`client_deliverable_decisions`): identity, decision, note, version, time. Status = the
  last decision. Approving an approved deliverable is a no-op. Decisions on a superseded or withdrawn
  deliverable are refused (row lock → no race with a new publish).
- Comments (`client_deliverable_comments`): from a client (always visible to clients) or from the team
  with `visibility` `client` (explicit "Reply to client") or `internal` (note).
- Notifications: `client_decision`, `client_commented` (CatalogNotification) → publisher + project
  admins with project access. Mail-only to the portal's active clients: invitation, new deliverable,
  team reply.
- "Attachments": the deliverable download (`…/download`, a sanitized standalone HTML file), gated by the
  same membership check.

## Changes
- Migration `2026_10_31_120000_create_client_portal_tables`.
- Models `ClientPortal`, `ClientPortalMember`, `ClientDeliverable`, `ClientDeliverableDecision`,
  `ClientDeliverableComment`; `ClientPortalPolicy` (view / manage / publish), registered.
- `app/Support/ClientPortal/*` (`ClientPortalAccess`, `DeliverableHtml`, `Deliverables`,
  `ClientPortalRecipients`).
- Controllers: `ClientPortalController` (client side), `ClientPortalTeamController` (team side).
- Routes: client section of `routes/portal.php`; `routes/client-portal.php` (team).
- Pages: `ClientPortal/Index`, `ClientPortal/Deliverable`, `ClientPortal/Denied`;
  `Projects/ClientPortal`, `Projects/ClientPortalDeliverable`. Types: own section in `types/index.d.ts`.
- Notifications: `ClientDecisionNotification`, `ClientCommentedNotification` (catalog);
  `ClientPortalInvitationNotification`, `ClientDeliverablePublishedNotification`,
  `ClientPortalReplyNotification` (mail only, to guests).
- Project page quick link "Client portal".

## Test plan
- PHPUnit `tests/Feature/ClientPortal/*`: invite / revoke / expiry lifecycle, other portal / project ids,
  drafts and internal notes never leak, internal links stripped, approve idempotent, new version
  supersedes, decision records identity + version, notifications, downloads gated.
- e2e `e2e/tests/client-portal.spec.ts`: admin enables, publishes a page version, invites a guest →
  guest follows the mail, approves → admin sees it; new version supersedes; revoked guest denied.

## Docs to update (lead merges)
ARCHITECTURE (client portal module), KNOWLEDGE (frozen deliverables, access rules) — notes in the report.
