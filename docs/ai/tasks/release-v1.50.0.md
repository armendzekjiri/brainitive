# Release v1.50.0: Sign in with Apple + mobile push delivery (server side)

**Branch:** release/v1.50.0 (ticket branches below, merged locally)
**Status:** done
**Started:** 2026-11-04
**Board cards:** BRAI-448 (mobile push), BRAI-449 (Sign in with Apple)

## Ticket branches (merged into release/v1.50.0 from pre-release)
- `chore/mobile-foundation`: Brainitive Mobile scope and plan; `mobile/` is its own repo (gitignored). [plan](./chore-mobile-foundation.md)
- `feat/mobile-push`: notifications with `push` on also go to mobile devices through the Expo push service. [plan](./feat-mobile-push.md)
- `feat/sign-in-with-apple`: Sign in with Apple on the web login, Welcome and `/app/authorize`; `sign_in.apple` in `GET /api/v1/instance`. [plan](./feat-sign-in-with-apple.md)
- `feat/apple-revoke-on-delete`: deleting an account revokes its Sign in with Apple authorization at Apple. [plan](./feat-apple-revoke-on-delete.md)
- `feat/mobile-push-receipts`: Expo push receipts (dead tokens cleared) + `POST /api/v1/devices/current/test-push`. [plan](./feat-mobile-push-receipts.md)
- `feat/mobile-store-pages`: public `/support` page, privacy policy "Mobile app" section, sub-processors (Expo, Apple). [plan](./feat-mobile-store-pages.md)
- `fix/live-editor-own-caret`: live editing no longer shows your own caret / name (the Lexical bridge hands only other people's awareness states).
- `chore/remove-tiptap`: the unused TipTap editor and its 27 packages removed (docs use Lexical).

## Release content
- HelpModal `CHANGELOG`: v1.50.0 entry.
- Homepage: the existing "Social login" card now lists Apple (no new card title, so
  `e2e/tests/homepage.spec.ts` is unchanged). Mobile push is server side only (the app isn't out
  yet), so no new card.
- docs-site: `integrations/apple.md`, configuration (Apple + mobile push env), integrations index.

## Gate
Pint, tsc, build, PHPUnit (PostgreSQL), e2e, docs-site build: see the release PR.
