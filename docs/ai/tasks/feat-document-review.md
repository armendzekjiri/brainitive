# Internal document review (BRAI-80)

**Branch:** feat/document-review
**Status:** done (merged into release/v1.42.0 by the lead)
**Started:** 2026-10-26
**Board card:** BRAI-80 (board item 182) · release contract: [release-v1.42.0.md](./release-v1.42.0.md) → "Ticket 3"

## Problem / goal
Teams want a second pair of eyes before a page goes live: ask colleagues to review the draft,
discuss text inline, get an approval, and (optionally) refuse to publish without one.

## Approach
- **A review is an immutable snapshot** of the page's draft at request time: `snapshot_name`,
  `snapshot_content`, `content_hash` (sha256 of the draft name + content JSON) and `edit_revision`.
  `Page::draftHash()` hashes the *current* editable state (draft columns of a published page, else
  `content`/`name`), so "stale" = `review.content_hash !== page.draftHash()` — computed, never stored.
- **Reviewers see the snapshot through the review** (`PageReviewPolicy::view` = can update the page OR
  active reviewer). They don't get `PagePolicy::view` on an unpublished draft.
- **Status** = `changes_requested` if any active reviewer requested changes, `approved` when all active
  reviewers approved, else `pending`. Active = reviewer row with a user that can still open the
  project (`Project::accessibleUsers`). Recomputed lazily (`PageReviews::recompute`) on every read,
  decision and publish check, so a reviewer removed from the project / workspace drops out without
  hooks; a deleted user keeps `user_name` on the history (`user_id` null on delete).
  Closed statuses: `cancelled`, `superseded` (a newer review was requested), `published`
  (approved snapshot went live).
- **Inline comments** (`page_comments`, threads + replies, resolve / reopen) anchor by text quote:
  `quote` + `quote_prefix` / `quote_suffix` (≤ 64 chars) over the whitespace-normalized text of the
  snapshot. Saved page HTML never changes. `QuoteAnchor` (PHP) and `components/page-review/anchors.ts`
  share the rule: 0 matches → outdated; 1 → that one; several → the one whose prefix and suffix match
  (or the single one matching either), otherwise outdated — never the wrong text. Unanchored = page-level.
  Threads of earlier reviews of the same page carry over (marked "from review #n") for people who
  can see that review.
- **Review required before publish** (`pages.review_required`, project admins): `PageController::publish`
  / `publishChanges` run in a transaction that locks the page row (`ReviewGate::publishing()`), re-reads
  it, applies the pending editor changes, and refuses unless an approved, open review has the current
  draft hash. Decisions / requests / cancels lock the same row, so they serialize with publishing.
  MCP `update-page` refuses on such published pages; restoring a version on them is refused too.
- **Side doors closed:** `PageController::update` sends name / icon of a published review-required
  page into the draft; `saveExcalidraw` refuses non-whiteboard review-required pages; the switch can't be
  set on whiteboards. The hash covers name + icon + content (embedded Excalidraw scenes excluded;
  snapshots don't copy them either). `Page::saveDraft` sends mention notifications after commit, so a
  publish holding the page lock never waits on mail. Lazy status writes only touch still-open reviews.
- **Notifications** (category `reviews`): `review_requested` (each reviewer once per review),
  `review_decided` (requester, on a changed decision), `review_commented` (thread participants +
  requester, deduped, never the actor, only people who can still see the review).

## Changes
- Migration `2026_10_31_130000_create_page_reviews_tables`: `pages.review_required`, `page_reviews`,
  `page_review_reviewers`, `page_comments`.
- Models: `PageReview`, `PageReviewReviewer`, `PageComment`; `Page::reviews()`, `draftState()`, `draftHash()`.
- `app/Support/PageReviews/*`: `PageReviews` (request / decide / cancel / recompute / comment),
  `ReviewGate`, `QuoteAnchor`, `ReviewNotifications`, `ReviewPresenter`.
- Policy `PageReviewPolicy` (registered in `AppServiceProvider`).
- `PageReviewController` + routes under `pages/{page}/reviews…` and `pages/{page}/review-settings`.
- Notifications: `ReviewRequestedNotification`, `ReviewDecidedNotification`, `ReviewCommentedNotification`.
- Frontend: `Pages/Pages/Review.tsx`, `components/page-review/*`, Show.tsx (banner, Request review,
  admin toggle, publish gating), types section "Document review (BRAI-80)".

## Test plan
- PHPUnit `tests/Feature/Pages/PageReviewTest.php`, `PageReviewGateTest.php`, `PageReviewCommentsTest.php`:
  stale approvals, publish blocked without approval (+ race: the gate re-reads the locked row),
  visibility matrix (reviewer sees only the snapshot, outsider / non-reviewer denied), outdated
  anchors, reviewer removal recompute, deleted reviewer keeps the name, notification dedup / no self-notify.
- `CatalogNotificationContractTest` gets the three classes.
- e2e `e2e/tests/document-review.spec.ts`: request → member comments on a selection + requests changes →
  author edits (stale) → new review approved → publish; review required refuses publishing without approval.

## Docs to update (lead merges)
ARCHITECTURE (page-review module), CONVENTIONS "Page workflow" (publish gate), KNOWLEDGE (anchoring,
snapshot visibility) — notes in the final report.
