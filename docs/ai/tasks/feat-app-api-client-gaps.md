# feat/app-api-client-gaps — last App API gaps for the desktop panel / New-task window

**Branch:** feat/app-api-client-gaps (from release/v1.49.0, merged back locally with --no-ff)
**Status:** done
**Started:** 2026-11-02

## Goal
What the desktop menu-bar panel and New-task window (later mobile / TV) still miss in `/api/v1`.

## Plan
1. **ETags** (`AnswersWithETag`, hash of body + user): `GET /me/work`, `GET /notifications` (first page only,
   no cursor), `GET /capture-destinations`.
2. **Notification `summary` + `url`**: one formatter class per worded type in `App\Support\Notifications\Summaries`
   (mirrors `resources/js/Components/NotificationMessage.tsx`), `ContractSummary` for every catalog / unknown type.
   Plain text (HTML / Markdown stripped, `@[Name](user:1)` → `@Name`, ≤ 200 chars). `url`: the payload link only
   when it is a page of this server the user can open now (`NotificationLinks`: route match + workspace / project /
   issue / doc / ticket access), absolute; else null.
3. **Timer** `today_logged_seconds` on the timer state (sum of the user's own work logs on their local today;
   the running timer not included) — part of the ETag body.
4. **Sync types** in the web's offline engine (`OfflineSync::TYPES`, appliers `TimerMutations`,
   `NotificationMutations`): `timer.start|stop|discard`, `notification.read`, `notifications.read_all`;
   `issue.create` goes through `Issues::create` (as REST) with `description` (Markdown) and `item_type_id`.
   New status `gone` (410) for a timer / notification that is no longer there. `invalid` timer stops carry
   `activities` + `default_activity_id` like the REST 422.
5. Docs: API.md, OpenAPI, docs-site, release file.

## Decision: a timer description needs a column
`work_timers` had nowhere to keep `description`; additive nullable `work_timers.description` (string 255).
It is the default note of the work log when the timer is stopped (REST, sync and the web pill). REST
`POST /me/timer/start` takes it too, `GET /me/timer` shows it (`timer.description`).
