# Legal & privacy — Brainitive Cloud and self-hosted installs

> **The legal texts in the app are drafts.** They were written to an EU / German standard (GDPR,
> TDDDG § 25, DDG § 5, BGB) from the actual code, but they are **not legal advice** and must be
> reviewed and confirmed by a qualified lawyer (ideally German IT / data-protection law) before they
> are relied on. Code comments in the pages say the same.

BRAI-156 (release v1.44.0). Inventory of every personal-data flow: [data-inventory.md](data-inventory.md).

## What changed

**Pages** (all light/dark, phone width, version + "last updated" per document, `LegalInfo::DOCUMENTS`):

| Route | Page | |
|---|---|---|
| `/privacy` | `Pages/Privacy.tsx` | rewritten from the inventory: controller, roles (C vs P), every purpose + legal basis, Umami, recipients, transfers, retention table from the code, rights, children |
| `/terms` | `Pages/Terms.tsx` | rewritten: parties, early access/free, acceptable use, content licence, customer as controller + DPA, AI disclaimer, German-style liability, termination/export/deletion, consumer withdrawal, changes with consent, law/jurisdiction |
| `/cookies` | `Pages/Cookies.tsx` | real list of cookies, local/session storage, IndexedDB, Cache Storage; the statistics consent (see / change / withdraw); embeds |
| `/imprint` (new) | `Pages/Legal/Imprint.tsx` | § 5 DDG fields from config; only rows with a value |
| `/legal/subprocessors` (new) | `Pages/Legal/Subprocessors.tsx` | OVHcloud, MXroute + "only if you use it" services |
| `/legal/dpa` (new) | `Pages/Legal/Dpa.tsx` | Art. 28 DPA template + TOMs annex derived from the code |
| `/refund` | unchanged text | Cloud only now |

Footer links (Imprint · Privacy · Terms · Cookies · Sub-processors) via `components/legal/LegalFooterLinks`
on the homepage, auth pages (GuestLayout), the app's account menu and every legal page (shared Inertia
prop `legalLinks`).

**Technical fixes:** Google Fonts → self-hosted Reenie Beanie (OFL, licence in
`resources/fonts/reenie-beanie/OFL.txt`); Excalidraw fonts no longer from esm.sh (copied at build by a
Vite plugin, `EXCALIDRAW_ASSET_PATH`); KaTeX CSS bundled instead of jsDelivr; unused Quill-CDN blade
deleted; Google favicon service removed; YouTube / Figma / X embeds two-click (`ExternalEmbedGate`);
cookie banner removed (it asked consent for cookies that don't exist and named Google Analytics);
Umami restricted (no customer pages, no secret URLs, no query strings) and **only with consent** (see
below); lead-form visit
id memory-only; Terms acceptance stored at sign-up (`users.terms_accepted_at`, `terms_version`);
self-service account deletion no longer deletes other people's work (`UserDeletion`); retention jobs
`auth:clear-resets`, `passport:purge`, `queue:prune-failed --hours=720`.

**Statistics with consent (`feat/analytics-consent`):** `app.blade.php` renders no Umami tag, only
`window.brainitiveAnalytics` (`Analytics::clientConfig()`) where `Analytics::enabledFor` allows it.
`resources/js/lib/analytics/consent.ts` (answer in localStorage `brainitive:analytics-consent`, GPC / DNT,
legacy `umami.disabled` = no), `tracker.ts` (loads the script after "Allow", `data-auto-track="false"`,
page views on load + Inertia `navigate`, path only, no titles; unloads on withdrawal),
`components/legal/AnalyticsConsent.tsx` (`AnalyticsConsentNotice` at the app root, `AnalyticsConsentSetting`
on `/cookies`). e2e: `ANALYTICS_E2E_OPT_IN` + header `X-E2E-Analytics: 1` (APP_ENV=e2e only).

**Customers' public pages:** Workspace settings → **Legal** (legal name, https privacy-policy URL,
https imprint URL; `workspaces.legal_*`). Support page + widget, ticket portal, lead forms, feedback
and client portals, share links, public plans and changelogs show "This page is operated by … using
Brainitive" with those links (`PublicOperator`, `OperatorNotice`); forms show a privacy line under
the submit button. Lead forms: optional / required consent checkbox with own text (`{organization}`
placeholder), stored as `leads.consent_at` + `leads.consent_text`, shown on the lead. Guest sign-in
page explains the platform's role. Mails (text only, in `toMail`): lead confirmation, ticket created
(to the contact), client-portal mails name the sender; the guest sign-in link mail links the privacy
policy.

## Configuration (`config/legal.php`)

Brainitive Cloud is detected from `APP_URL` (brainitive.app / www / dev) or forced with
`LEGAL_CLOUD=true|false`. Cloud defaults live in `App\Support\Legal\LegalInfo::CLOUD_DEFAULTS`
(contact hello@brainitive.app, OVHcloud, MXroute). Self-hosted installs show **only** what their
operator sets, and the Cloud-only documents (DPA, sub-processors, refund) return 404 there.

| Env | Meaning |
|---|---|
| `LEGAL_OPERATOR_NAME`, `LEGAL_OPERATOR_DESCRIPTION`, `LEGAL_ADDRESS`, `LEGAL_EMAIL`, `LEGAL_PHONE` | imprint / controller |
| `LEGAL_REPRESENTATIVE`, `LEGAL_REGISTER`, `LEGAL_VAT_ID`, `LEGAL_CONTENT_RESPONSIBLE` | imprint (rows hidden when empty) |
| `LEGAL_DPO`, `LEGAL_EU_REPRESENTATIVE`, `LEGAL_SUPERVISORY_AUTHORITY` | privacy policy |
| `LEGAL_GOVERNING_LAW`, `LEGAL_JURISDICTION` | terms |
| `LEGAL_HOSTING_PROVIDER`, `LEGAL_HOSTING_LOCATION`, `LEGAL_EMAIL_PROVIDER`, `LEGAL_EMAIL_PROVIDER_LOCATION`, `LEGAL_BACKUP_PROVIDER`, `LEGAL_BACKUP_LOCATION` | sub-processors |
| `LEGAL_SERVER_LOG_RETENTION`, `LEGAL_BACKUP_RETENTION`, `LEGAL_ANALYTICS_RETENTION` | retention table (free text, e.g. `14 days`) |
| `LEGAL_PRIVACY_URL`, `LEGAL_TERMS_URL`, `LEGAL_IMPRINT_URL` | self-hosted: the operator's own documents |
| `ANALYTICS_HOSTS`, `ANALYTICS_UMAMI_SCRIPT`, `ANALYTICS_UMAMI_WEBSITE_ID` | Umami (defaults = brainitive.app's) |

Unknown facts render as an amber "[… — to be confirmed]" marker (`ToConfirm`) — only in the
sub-processor list, the retention table and the DPA annex. The imprint never shows a TODO.

## Open questions for the operator

1. **Name and summonable postal address** — § 5 DDG (imprint) and Art. 13 (1) (a) GDPR normally
   require the operator's full name and a postal address (no P.O. box). The operator decided not to
   publish them for now; the imprint shows "run by an individual (sole proprietor)" + e-mail only.
   **This is an open legal risk the operator accepted** (Abmahnung risk, fines under § 33 DDG). Usual
   solution: a c/o or business address service ("Impressumsservice") → set `LEGAL_OPERATOR_NAME` and
   `LEGAL_ADDRESS`.
2. Country of establishment → `LEGAL_GOVERNING_LAW`, `LEGAL_JURISDICTION`, `LEGAL_SUPERVISORY_AUTHORITY`
   (currently generic wording).
3. OVHcloud: contracting entity and data-centre location(s) of app, backup and analytics servers;
   is the OVH DPA accepted? → `LEGAL_HOSTING_LOCATION`, `LEGAL_BACKUP_LOCATION`.
4. MXroute: contracting entity, server location, DPA / SCCs available? → `LEGAL_EMAIL_PROVIDER_LOCATION`;
   the safeguard cell stays "to be confirmed" until then (may require a different provider if no DPA).
5. nginx access-log retention on the app server and on the Umami server → `LEGAL_SERVER_LOG_RETENTION`.
6. Backup frequency, retention, encryption, restore tests → `LEGAL_BACKUP_RETENTION` + DPA annex 2.
7. Umami: version (≥ 2.13 for `data-before-send`, `data-exclude-hash`), data retention, does its
   server log IPs? → `LEGAL_ANALYTICS_RETENTION`.
8. SSH key-only access, MFA on the OVH / GitHub accounts (DPA annex 2 marks this "to be confirmed").
9. Production `.env`: nothing is required (Cloud is detected from `APP_URL`); set the env keys above as
   the answers come in.

## What a lawyer must confirm

- ~~**Umami without consent** (§ 25 TDDDG)~~ — **resolved** (`feat/analytics-consent`): the operator
  decided to ask. Umami now loads only after the visitor clicks "Allow" in a small notice (equal
  "Allow" / "No thanks" buttons, nothing pre-selected, no "accept by scrolling", not shown again after
  an answer); GPC / Do Not Track = no; withdrawal on `/cookies` stops it at once. Legal basis in the
  privacy policy: Art. 6 (1) (a) GDPR + § 25 (1) TDDDG. Still for the lawyer: the notice wording and
  whether storing the answer itself (`brainitive:analytics-consent`) is fine under § 25 (2) no. 2.
- The imprint without name/address (see question 1).
- Liability clause (§§ 307, 309 no. 7 BGB; the free-service limitation to intent/gross negligence,
  analogous §§ 521, 599 BGB), the consumer withdrawal text (§ 312 (1a) BGB — personal data as
  "payment"; a formal Widerrufsbelehrung/model form may be required), and the change-of-terms clause
  (BGH XI ZR 26/20 — no tacit consent; we require acceptance).
- Minimum age 16 and whether Art. 8 GDPR matters (we don't rely on consent for accounts).
- DPA template (Art. 28 (3)), audit clause, 30-day sub-processor notice, the transfer statements for
  Google / Microsoft / GitHub (DPF certification status) and push services.
- Role split: guest identities (platform = controller) vs portal content (customer = controller);
  share links and calendar feed.
- Whether Brainitive Cloud (free, but with announced paid plans) is a "geschäftsmäßiger digitaler
  Dienst" (imprint duty) — assumed yes.

## Self-service data export (Art. 15 / 20) — done (feat/data-export)

Profile → **Your data** → "Download my data": a background job writes a ZIP of JSON files + README.txt
about the signed-in account (`App\Support\Privacy\PersonalDataArchive`, file list in its README and in
[`data-inventory.md`](data-inventory.md) §14). The signed link works 24 h and only in the owner's
session; the file is deleted then (`exports:cleanup`), the `data_exports` row after 30 days; one request
per hour. Never in the ZIP: password hash, tokens, app passwords, calendar credentials, push keys, other
people's comments / pages / private content, content of workspaces the person left (the workspace is
controller — such requests go to it, see Privacy "Your rights"). Same on self-hosted installs.

## Follow-ups (not in this ticket)

- Purge soft-deleted content after a trash period; retention for `admin_audit_logs`, `activity_logs`,
  `captures`, portal identities, `sent_*reminders`; daily log rotation (`LOG_STACK=daily`).
- Remove the TEMPORARY IP/UA logging in `LogMcpRequest`.
- Helpdesk attachments are on the public disk (reachable by URL) — move behind signed URLs.
- OAuth-only accounts can't delete themselves without a password reset.
- Terms notice in the homepage sign-in modal (Welcome.tsx OAuth buttons).
- Re-consent flow when `LegalInfo::DOCUMENTS['terms']['version']` changes (users.terms_version < current).
- Operator notice on public bookmark / snippet share pages.
- Status mails to ticket contacts (reply / resolved) could also carry the operator line.

## Maintenance rule

**Every new integration, processor, outbound host, cookie / storage key or retention job →** update
[data-inventory.md](data-inventory.md), `Pages/Legal/Subprocessors.tsx`, `Pages/Privacy.tsx` and
`Pages/Cookies.tsx` in the same PR, and bump the document's version/date in
`LegalInfo::DOCUMENTS` (a material change to the terms needs the users' acceptance).
`tests/Feature/Legal/LegalPagesTest.php` fails when a known third-party font/CDN host appears in the
app's sources.
