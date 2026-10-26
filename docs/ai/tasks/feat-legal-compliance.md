# feat/legal-compliance — Legal & privacy review (BRAI-156, board item 363, release v1.44.0)

## Goal
The user: "on what we now all collect are we okay with term of use, collecting data (umami),
datenschutz etc! check everything and make like a real lawyer … like the support pages that we create".
Inventory every personal-data flow, fix technical privacy issues, rewrite the legal pages (drafts for a
lawyer to confirm), add imprint / sub-processors / DPA, and make customers' public pages name the
customer as controller. Details for humans: `docs/legal/README.md`, `docs/legal/data-inventory.md`.

## Plan (done)
1. Inventory (Explore sweep of 26 areas) → `docs/legal/data-inventory.md`.
2. Technical fixes: self-host Reenie Beanie; Excalidraw fonts from our build; KaTeX CSS bundled;
   delete unused Quill-CDN blade; drop Google favicon fallback; two-click embeds; remove the dishonest
   cookie banner; restrict Umami; lead-form visit id memory-only; safe self-service account deletion;
   retention schedules; Terms acceptance stored at sign-up.
3. `config/legal.php` + `App\Support\Legal\{LegalInfo, Analytics, PublicOperator}`, `LegalController`,
   pages `Privacy`, `Terms`, `Cookies`, `Legal/{Imprint,Subprocessors,Dpa}`, `components/legal/*`.
4. Workspace settings → Legal; operator line on support page/widget/ticket portal, lead forms,
   feedback + client portals, share links, public plans, changelogs; lead consent checkbox.
5. Tests: `tests/Feature/Legal/*` (PHPUnit), `e2e/tests/legal.spec.ts`.

## For the lead — KNOWLEDGE.md candidates

### Legal pages read one config, Cloud vs self-hosted (BRAI-156)
`config/legal.php` is read only through `App\Support\Legal\LegalInfo`. Cloud = `LEGAL_CLOUD` or
APP_URL host in `LegalInfo::CLOUD_HOSTS` (brainitive.app, www., dev.); Cloud facts the operator gave
(hello@brainitive.app, OVHcloud, MXroute) are `CLOUD_DEFAULTS` — never in config defaults, so a
self-hosted install can't show them. Self-hosted: `/legal/dpa`, `/legal/subprocessors`, `/refund`
404; `/imprint` 404 until an operator field or `LEGAL_IMPRINT_URL` is set. Shared prop `legalLinks`
(nullable entries) drives every footer (`LegalFooterLinks`). e2e runs as Cloud (`LEGAL_CLOUD=true`
in `e2e/env.ts`).

### Customers' public pages carry an `operator` prop
`PublicOperator::for($workspace)` → `{name, privacy_url, imprint_url, configured, platform_*}` (https
URLs only, re-checked on read). Without a legal name it falls back to the workspace name **only**
with `nameFallback: true` (support page / widget / lead forms, which always showed the organisation);
token links (share links, plans, changelogs, portals, ticket links) stay generic (`named: false`) so
they don't reveal internal workspace names (security review, BRAI-156). Pages render `OperatorNotice`; `PortalLayout` reads the `operator`
page prop itself, so a new portal page only has to pass it. Visitor-facing mails append
`PublicOperator::mailLine()` (plain text, Markdown-safe). A new public page for a workspace's
visitors must pass `operator` too, and its path prefix goes into `Analytics::EXCLUDED_PREFIXES`.

### Lead consent is proof, not a field
`settings.consent` = off | optional | required, `settings.consent_text` (`{organization}` placeholder,
default `LeadFormOptions::DEFAULT_CONSENT_TEXT`). The server renders the text
(`LeadFormOptions::consentText`), enforces "required" (422 `consent`) and stores exactly that text +
`consent_at` on the lead; a `consent: true` without a checkbox stores nothing. The lead-form visit id
for funnel events is memory-only (no sessionStorage: § 25 TDDDG) — a reload counts as a new visit.

### No third-party requests from our own pages
Fonts self-hosted (`resources/fonts/…`, @fontsource); Excalidraw fonts copied to
`public/build/excalidraw-assets/fonts` by the `brainitive-excalidraw-fonts` Vite plugin and found via
`window.EXCALIDRAW_ASSET_PATH` (app.blade + excalidraw-editor.blade) — without it Excalidraw silently
loads them from esm.sh. YouTube / Figma / X embeds render inside `ExternalEmbedGate` (click to load,
"always" per provider in localStorage `brainitive-embeds-allowed`). `LegalPagesTest` greps the sources
for known CDN hosts.

### Self-service account deletion uses UserDeletion
`ProfileController::destroy` refuses while the user owns a workspace with other members and then
calls `App\Support\Admin\UserDeletion::delete()` (content in other people's workspaces handed to their
owners) instead of `$user->delete()`, whose FK cascades wiped other people's projects/boards/items.

## CONVENTIONS.md candidates
- Routes: public legal pages are `/privacy`, `/terms`, `/cookies`, `/imprint`, `/legal/*` (LegalController).
- New cookie / storage key / processor / outbound host → update `docs/legal/data-inventory.md`,
  `Pages/Cookies.tsx`, `Pages/Privacy.tsx`, `Pages/Legal/Subprocessors.tsx`; bump `LegalInfo::DOCUMENTS`.
- Iframes / scripts from other services in content → wrap in `ExternalEmbedGate`.

## Migrations
- `2026_11_02_160000_add_terms_acceptance_to_users_table` (`terms_accepted_at`, `terms_version`)
- `2026_11_02_160100_add_legal_fields_to_workspaces_table` (`legal_controller_name`, `legal_privacy_url`, `legal_imprint_url`)
- `2026_11_02_160200_add_consent_to_leads_table` (`consent_at`, `consent_text`)

## Changelog line
- **Legal & privacy (BRAI-156):** new imprint, sub-processor list and data processing agreement;
  rewritten privacy policy, terms of use and cookie page; no more requests to Google Fonts or other
  CDNs (embeds load on click); no cookie banner because nothing optional is stored; workspaces can name
  their legal entity and link their privacy policy and imprint on support pages, forms and portals;
  lead forms get an optional or required consent checkbox that is saved with the lead.

## Notes for the merge
- Mail changes are text-only lines in `toMail()` of `LeadConfirmationNotification`,
  `TicketCreatedNotification` (contact branch), `PortalLoginLinkNotification`,
  `ClientPortalGuestNotification` — no layout/template changes (the email-template agent owns those).
- Welcome.tsx: only the footer links block (+ removed `<CookieConsent />`); the homepage sign-in modal
  still lacks the Terms notice (follow-up).
- `public/js/helpdesk-widget.js` rebuilt (privacy line under the widget's submit button).
