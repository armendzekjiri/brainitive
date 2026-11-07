# Nightly Firefox / WebKit e2e green again

**Branch:** fix/nightly-e2e-browsers (from pre-release)
**Status:** done (local); not pushed
**Started:** 2026-11-07

## Problem
`E2E nightly` (`.github/workflows/e2e-nightly.yml`, Firefox + WebKit on `pre-release`) failed every
night since late October while the per-push Chromium run stayed green. Runs 41956254910, 42151341544,
42330050156 (logs + Playwright artifacts) were read; the 2026-11-07 WebKit job was also cut off by a
runner shutdown (infrastructure, not a test).

## Causes and fixes
| Test | Browser | Cause | Fix |
|---|---|---|---|
| homepage.spec.ts:172 / :226, analytics-consent.spec.ts:128, legal.spec.ts:57 (also homepage :74 locally) | WebKit | The homepage (`Welcome.tsx`) rendered at 0–3 fps in WebKit without GPU compositing: three animated 100–120 px `blur()` blobs under ~15 `backdrop-blur-sm` cards repainted the page every frame. Clicks / `scrollIntoViewIfNeeded` waited forever for "stable". | **App:** blobs are radial gradients (same colours, no filter); cards / footer drop `backdrop-blur-sm` (they only blurred the near-flat background). The nav bars keep theirs. 61–110 fps afterwards. |
| app-sign-in.spec.ts:72 / :143 | WebKit | `page.route('**/app/authorize')` never saw the POST: the page is controlled by `sw.js`, and WebKit sends a controlled page's requests past Playwright's routing. | **Test:** `test.use({ serviceWorkers: 'block' })`. |
| plan-limits.spec.ts:120 | WebKit | Same: the faked 402 never applied, the real server answered 409 ("Send invitation?"). | **Test:** `serviceWorkers: 'block'`. |
| live-recovery.spec.ts:151 | WebKit | Same: the faked 503 for the collab session request never applied. | **Test:** `serviceWorkers: 'block'`. |
| quick-capture.spec.ts:27 | WebKit | The captures search is a debounced `router.get`; it fired after the test moved on and interrupted the next `page.goto`. | **Test:** wait for `?q=cc` in the URL before leaving. |
| item-types-toggle.spec.ts:37 | Firefox | Escape (close the type Select) + Escape (close the dialog) back to back: the closing Select was still the top dismissable layer and took the second Escape. | **Test:** wait for the listbox to be hidden between the two. |
| document-review.spec.ts:57 | Firefox | Every step passed, the two-session journey (~10 page loads, two typed drafts) just ran past the 90 s CI budget (~4 s per navigation on the runner). | **Test:** `test.slow()`. |
| document-review.spec.ts:57 (found locally) | Firefox | `usePagePresence` set `users` to `undefined` from a heartbeat that resolved without its JSON body while the page was being left → "can't access property filter" in `Pages/Show`. | **App:** the hook ignores answers without a `users` array. |

Not reproduced locally: time-log.spec.ts:46 (WebKit, flaky once on 2026-11-05, passed on retry; 20/20
locally).

## Verification
- WebKit: homepage + analytics-consent + legal `--repeat-each=3` (83 passed); app-sign-in +
  live-recovery + plan-limits + quick-capture `--repeat-each=3` (68 passed, 12 pre-existing skips).
- Firefox: document-review + item-types-toggle `--repeat-each=3 --workers=4` (11 passed).
- Full suites (6 workers): WebKit 322 passed / 26 skipped / 0 failed; Firefox 312 passed / 34 skipped /
  2 failed (ai-replace:24, billing-settings:16 — never failed nightly, both 14/14 on `--repeat-each=3`
  re-run: load on the local machine).
- Chromium: the changed specs still pass (54 passed).
- Gate: `npx tsc --noEmit`, `npm run build`.

## Docs
KNOWLEDGE.md → E2E suite: "Nightly Firefox / WebKit traps".
