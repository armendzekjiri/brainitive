# Public feedback portal (BRAI-60)

**Branch:** feat/feedback-portal (from `release/v1.42.0`)
**Status:** in-progress
**Started:** 2026-10-26
**Board card:** BRAI-60 (item 162) — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-162
**Contract:** [release-v1.42.0.md](./release-v1.42.0.md) → "Ticket 1"

## Goal
Per-project, opt-in public portal: visitors read approved ideas, signed-in identities (guest sign-in
from the foundation) submit, vote and follow; project admins moderate, set public status, merge
duplicates and turn ideas into board cards. Followers are mailed once per status change.

## Approach
- Tables `feedback_portals` (one per project), `feedback_posts` (number per portal, moderation
  pending/approved/rejected, public status open/planned/building/released/declined, cached
  `votes_count`, `merged_into_id`, `item_id`), `feedback_votes` + `feedback_subscriptions` (unique per
  post + identity), `feedback_events` (moderator audit), `feedback_status_mails` (mail dedup).
- `App\Support\Feedback\FeedbackVotes` (vote / unvote / follow: `insertOrIgnore` + atomic counter in
  one transaction), `FeedbackModeration` (approve, reject, status, merge, create / link card — all
  audited), `FeedbackPresenter` (the only public shape: never item, author email, moderation data),
  `FeedbackNotifier` (feedback_submitted to project admins; status mail to followers once).
- Public routes in `routes/portal.php` under `/feedback/{slug}`; team routes in `routes/feedback.php`
  (`projects.feedback.*`, project admins = `ProjectPolicy::update`).
- No new policy class (moderation = `update` on the project; create card = `createItems` on the
  board; link card = `view` on the item + same project).

## Changes
- Migration `2026_10_31_110000_create_feedback_portal_tables`.
- Models `FeedbackPortal`, `FeedbackPost`, `FeedbackVote`, `FeedbackSubscription`, `FeedbackEvent`.
- Controllers `FeedbackPortalController` (public), `FeedbackModerationController` (team).
- Notifications `FeedbackSubmittedNotification` (catalog), `FeedbackStatusChangedNotification` (mail-only, guests).
- Pages `Feedback/Index`, `Feedback/Show`, `Feedback/Roadmap`, `Feedback/Unsubscribe`, `Projects/Feedback`.
- Shared: `routes/portal.php` (Ticket 1 section), `routes/web.php` (one require), `Projects/Show.tsx`
  (one quickLinks line), `types/index.d.ts` (own section), `CatalogNotificationContractTest`.

## Test plan
- PHPUnit `tests/Feature/Feedback/*`: permission matrix, disabled portal 404, pending hidden,
  vote uniqueness + retry, merge union + redirect, idempotent create card, cross-project link
  rejected, public fields, follower mail once + signed unfollow, audit rows, throttles.
- e2e `e2e/tests/feedback-portal.spec.ts`: the contract journey.

## Notes for the lead (docs)
See the final report of this branch.
