# feat/analytics-consent — Umami only with the visitor's consent (BRAI-156 follow-up, release v1.44.0)

## Goal
The legal review (docs/legal/README.md, "What a lawyer must confirm") flagged Umami without consent as a
§ 25 (1) TDDDG risk: the script reads device information (screen size, language) in the browser. The
user decided: "then do with consent". Analytics now runs only after an active "Allow".

## Plan
1. Server: `Analytics::enabledFor` rules unchanged (brainitive.app hosts, excluded customer / secret
   paths); new e2e-only opt-in (`ANALYTICS_E2E_OPT_IN` + header `X-E2E-Analytics: 1`, honoured only
   with APP_ENV=e2e, like `X-E2E-Collab`). `app.blade.php` no longer renders the Umami `<script>`; it
   renders only a config object (`window.brainitiveAnalytics` = script URL, website id, excluded
   prefixes) where analytics would be allowed.
2. Client: `lib/analytics/consent.ts` (choice in localStorage, DNT / GPC, legacy `umami.disabled`),
   `lib/analytics/tracker.ts` (injects the script only while consented, `data-auto-track="false"`,
   manual page views on Inertia `navigate`, unload on withdrawal).
3. UI: `components/legal/AnalyticsConsent.tsx` → `AnalyticsConsentNotice` (bottom, non-blocking, equal buttons) mounted at
   the app root (app.tsx); `AnalyticsConsentSetting` on `/cookies`.
4. Texts: Privacy (consent, Art. 6 (1) (a) GDPR + § 25 (1) TDDDG, withdrawal), Cookies (consent key),
   docs/legal/data-inventory.md + README (risk resolved), bump document versions.
5. Tests: PHPUnit (`LegalPagesTest`), e2e `analytics-consent.spec.ts`, adjust `legal.spec.ts`.

## Decisions
- **Server rules unchanged.** `Analytics::enabledFor` (hosts + excluded prefixes) still decides; it now
  gates a config object instead of a `<script>` tag. A first load on an excluded path gets no config,
  so no analytics for that SPA session (conservative); client-side navigations into excluded paths are
  skipped by the tracker and dropped by the `data-before-send` filter.
- **No cookie, localStorage only.** `brainitive:analytics-consent` = `{choice, version: 1, at}`. Strictly
  necessary to remember the answer (§ 25 (2) no. 2). Bump `CONSENT_VERSION` in
  `lib/analytics/consent.ts` to ask everybody again. Storage blocked → the answer holds for this page load.
  Not stored on the account (optional in the brief; per-browser matches "this browser" in the texts).
- **GPC / DNT = no**, checked in the browser (`navigator.globalPrivacyControl`, `doNotTrack`); no notice,
  and /cookies shows the signal instead of buttons (the signal is not overridable there).
- **Legacy opt-out** `umami.disabled` (shipped earlier in this release) counts as "no" and is removed on
  any answer (Umami itself would otherwise drop everything after an "Allow").
- **Manual page views.** `data-auto-track="false"`; `tracker.ts` calls `umami.track(fn)` on the script's
  load and on Inertia `navigate` (fires on first load too → deduplicated by path), path only, **no
  page title** (titles can name private documents), referrer = previous path / external origin+path.
  Withdrawal removes the script tag and `window.umami` at once; later navigations send nothing.
- **Notice** (`components/legal/AnalyticsConsent.tsx`, mounted next to `<App>` in app.tsx, outside the
  Inertia tree — reads the path via `router.on('navigate')`): bottom, non-blocking, no dialog/focus trap,
  `aria-live="polite"` region filled after mount, two identical buttons ("Allow" / "No thanks"), link
  "What we count" → `/privacy#analytics`. Shown on brainitive.app pages incl. the signed-in app.
- **e2e switch:** `ANALYTICS_E2E_OPT_IN=true` (e2e/env.ts) + request header `X-E2E-Analytics: 1`, honoured
  only with APP_ENV=e2e (like `X-E2E-Collab`) — other journeys never see the notice.
- Documents: privacy 2.1 / cookies 2.1 (2026-10-27) in `LegalInfo::DOCUMENTS`.

## Not touched (for the lead)
- `HelpModal.tsx` v1.44.0 entry says "no cookie banner because nothing optional is stored" — still no
  cookie banner, but statistics are now optional and asked for; suggest: "…no cookie banner; our own
  visit statistics run only if you allow them".
- Welcome.tsx: nothing (the notice lives at the app root).

## KNOWLEDGE.md candidate
### Umami runs only with consent (BRAI-156 follow-up)
The server never renders the tracker: `app.blade.php` emits `window.brainitiveAnalytics`
(`Analytics::clientConfig()`) only where `Analytics::enabledFor` allows; `lib/analytics/tracker.ts`
injects Umami after "Allow" (`brainitive:analytics-consent` in localStorage, GPC/DNT = no) with
auto-track off and counts Inertia navigations itself (path only, no title). Don't add `data-auto-track`
back or a `<script src=stats…>` in blade — that would track without consent. A new public page for a
workspace's visitors still needs its prefix in `Analytics::EXCLUDED_PREFIXES`. e2e: header
`X-E2E-Analytics: 1` + `ANALYTICS_E2E_OPT_IN`; route `https://stats.novabrainhub.de/**` (see
analytics-consent.spec.ts's fake tracker) so nothing leaves the machine.

## Changelog line
- **Statistics only with your consent:** our own privacy-friendly visit statistics (Umami) now run only
  after you click "Allow" in a small notice — "No thanks", Global Privacy Control or Do Not Track mean
  nothing is loaded; change or withdraw your choice any time on the Cookies page.
