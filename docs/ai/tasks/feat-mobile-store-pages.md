# Mobile app store pages: public /support + "Mobile app" in the privacy policy

**Branch:** feat/mobile-store-pages
**Status:** done
**Started:** 2026-11-04
**Board card:** — (Brainitive MCP not connected in this session)

## Problem / goal
The App Store / Google Play listing of the Brainitive mobile app (Expo, `app.brainitive.mobile`)
needs a public **support URL** and a **privacy policy URL** that covers the app. We have neither a
support page nor a word about the app in `/privacy`.

## Approach
- **`/support`** (route `support`) next to the legal pages, rendered by `LegalController::support`
  with `LegalInfo::pageProps('support')`, so it uses the same `LegalLayout` (header, version line,
  legal footer). Content: contact + FAQ for the mobile app (signing in, self-hosted server,
  notifications, deleting the account, billing / admin on the web only, reporting a bug).
  Cloud: contact is the operator's e-mail (`hello@brainitive.app`). Self-hosted: contact is the
  installation's operator (if configured), plus the developers' address for problems with the app
  itself — never Brainitive Cloud's operator data as "the operator".
- No collision with the helpdesk route `/support/{publicSlug}`: that one needs a second segment.
  The bare `/support` is already under `Analytics::EXCLUDED_PREFIXES` (`'/support'` matches the bare
  path too) — fine, the page simply gets no visit statistics.
- Legal pages are English only (no i18n in the app) → the support page is English only too.
- **Privacy policy:** new section "Mobile app" (after "Browser extension"), following
  `mobile/docs/store/privacy.md` § "Website privacy policy": server the user connects to, Expo push
  service → APNs / FCM (push token + notification text), device storage (tokens in keychain /
  keystore, offline cache, queued changes, drafts — removed on sign-out), camera / photos only when
  adding a picture, no analytics / ads / tracking SDKs, Sign in with Apple, account deletion. Also
  Expo + Apple in the recipients table, transfers section, retention (push token). Privacy version
  2.3 → 2.4, updated 2026-11-04.
- Footer: `support` added to `LegalInfo::links()` / `LegalLinks` and `LegalFooterLinks` (used by
  the homepage footer, legal pages, guest + app layouts, download page).

## Changes
- Controller / routes: `LegalController::support`, `Route::get('/support', …)->name('support')`.
- `LegalInfo::DOCUMENTS['support']`, `links()['support']`.
- Frontend: `Pages/Support.tsx`, `Pages/Privacy.tsx` (section + tables), `LegalFooterLinks`,
  `types/index.d.ts` (`LegalLinks.support`).

## Test plan
- PHPUnit `tests/Feature/Legal/LegalPagesTest.php`: guest `/support` → 200 `Support` (Cloud and
  self-hosted), `/support/{slug}` still resolves to `helpdesks.support-page`, footer prop.
- e2e `legal.spec.ts`: the homepage footer list includes Support (not run locally — low RAM).

## Docs to update
- ARCHITECTURE "Legal & privacy" → pages list.

## Open questions
- Changelog (HelpModal `CHANGELOG`): one entry per release, written on the release branch; version
  not decided yet → no entry here. Suggested item: "New public support page (/support) with help for
  the mobile app, and the privacy policy now covers the mobile app."
- `mobile/docs/store/privacy.md` also says "Add Expo to /legal/subprocessors" — done here as well
  (Expo is a processor for push).
- Sign in with Apple and Expo push live on unmerged branches (`feat/sign-in-with-apple`,
  `feat/mobile-push`); the policy text describes them, so this should ship in the same release.
