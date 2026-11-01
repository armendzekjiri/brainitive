# Notification email digest (BRAI-429)

**Branch:** feat/notification-email-digest (from release/v1.48.0)
**Status:** done
**Started:** 2026-10-31
**Board card:** BRAI-429

## Problem / goal

Five cards assigned to one person in a few seconds = five emails. Batch closely timed activity
emails into one digest per recipient **and** workspace; the bell, web push and realtime stay
instant.

- Fixed window: `NOTIFICATION_EMAIL_DIGEST_SECONDS` (default 60) from the **first** pending event,
  never extended. Later events open the next window.
- One email with N linked entries (type, key + title, context, time, link) and a subject with the
  count; a single event = a normal-looking email ~60 s later.
- Claim atomically, dedupe, safe under concurrent workers / retries.
- Preferences (per category) and access re-checked at send time; never mix workspaces.
- Laravel queue + delayed job, no cron. `0` = off (old behaviour). Sync queue → immediate (a sync
  "delayed" job runs at once, so there is nothing to wait for).

## Approach

### Where it hooks in
Every member notification picks its channels through `RespectsPreferences::channelsFor()`. When
the digest is on, the type is eligible, the class implements `App\Notifications\Contracts\DigestibleMail`
and the notifiable is a `User`, `mail` is swapped for `App\Notifications\Channels\DigestMailChannel`.
Nothing else changes: `database` / `WebPushChannel` still go out at once, and the class's own
`toMail()` is untouched (used when the digest is off, on the sync queue, or as a fallback when an
entry can't be built).

### Storage: a small table, not the cache
`pending_notification_emails` (one row per captured email event):
`user_id`, `workspace_id` (FKs, cascade), `type`, `subject_type` + `subject_id` (the model whose
`view` policy is re-checked), `dedupe_key` (sha1 of type, subject, entry text), `entry` (json:
label, title, subject_key, subject_title, context, body, url, mail_subject), `due_at` (window end),
`claim_token` + `claimed_at`, timestamps. Index `(user_id, workspace_id, due_at)` and
`claim_token`. Rows are deleted once the mail is sent; leftovers are pruned after 7 days
(`model:prune`).

Why a table: it survives restarts and works with every queue / cache driver self-hosters run
(database, redis), the claim is one atomic `UPDATE … WHERE claim_token IS NULL`, and it is easy
to inspect. A cache list would be lost on `cache:clear`, isn't atomic on the database / file
stores, and can't be queried per recipient.

### Window + job
`MailDigest::capture()` (under a short cache lock per user + workspace, in a DB transaction):
- an exact duplicate of a still-unclaimed row → dropped;
- an open window (unclaimed row with `due_at` in the future) → join it (same `due_at`);
- else new window: `due_at = now + interval`, dispatch `SendNotificationDigest(user, workspace)`
  delayed to `due_at`. Inside the same transaction, so with the database queue the job row and the
  event row commit together.

`SendNotificationDigest` (tries 3, backoff 30 s / 2 min):
- cache lock per user + workspace (a duplicate delivery waits / is released, never sends twice);
- claim: `UPDATE … SET claim_token = <job token> WHERE user, workspace, claim_token IS NULL,
  due_at <= now` — the token is made in the constructor, so a **retry** of the same job picks up the
  rows it claimed before (nothing lost), and a second job can't take them;
- filter: user exists and not suspended, still a workspace member, email still on for the type's
  category, subject still exists and `Gate::forUser($user)->allows('view', $subject)`; dedupe;
- send `NotificationDigestNotification` (branded member mail) — 1 entry: the normal single mail
  layout; several: the list;
- delete the claimed rows. `failed()` logs and releases the claim (the rows go out with the
  next digest for that user + workspace).

Delivery is at-least-once: a crash after the SMTP hand-off but before the delete can resend on
retry; never twice from two workers.

### Which emails batch
Eligible (`MailDigest::TYPES`): `item_assigned`, `task_assigned`, `mentioned`, `item_commented`,
`item_status_changed`, `page_updated`, `sprint_started`, `sprint_completed`.
- Mentions: yes — a page or comment with several mentions, or a busy thread, is exactly the burst
  this is for; the bell and push stay instant for urgency.
- Comments / status changes: yes — bulk moves and chatty threads were the noisiest emails.

Never batched (immediate, unchanged):
- Not in the catalog / not to members: verification, password reset, workspace invitations,
  portal sign-in links, client-portal / feedback / booking guest mails, ticket contact mails
  (replies, acknowledgments, resolved), lead confirmations, billing report mails, admin test mail.
- Account / security-ish: data export ready, project export ready, webhook disabled, GitHub sync
  failed, workspace role changed / invitation accepted (workspace category).
- Time-critical: helpdesk (tickets, SLA warnings / breaches), leads, bookings, reviews / client
  decisions, reminders / due / daily task digest (already scheduled or time-specific),
  automation notices (rule-defined content), feedback moderation.

### Config
`config/notifications.php` → `email_digest_seconds` (`NOTIFICATION_EMAIL_DIGEST_SECONDS`, default
60, `0` = off). `.env.example`, `docs/self-hosting.md`, `docs-site/self-hosting/configuration.md`.

## Tests
`tests/Feature/Notifications/EmailDigestTest.php` (PHPUnit, Queue::fake for the delayed job, the
array mailer for real rendered mails):
5 assignments → 5 bell rows + 0 mails, 1 delayed job, then 1 mail with 5 entries; 1 event → normal
mail after the window; events after the window → next batch; retried / concurrent job → one mail;
transactional mail immediate; preference off → nothing; lost access → entry dropped; two workspaces
→ two mails; interval 0 / sync queue → immediate; duplicates suppressed; failure releases claim.

No e2e: no UI changed, and e2e + dev.brainitive.app run the sync queue (digest = immediate there).

## Files
- `config/notifications.php` (new), migration `2026_11_06_142900_create_pending_notification_emails_table`
- `app/Models/PendingNotificationEmail.php`, `app/Support/Notifications/MailDigest.php`,
  `app/Jobs/SendNotificationDigest.php`, `app/Notifications/Channels/DigestMailChannel.php`,
  `app/Notifications/Contracts/DigestibleMail.php`, `app/Notifications/NotificationDigestNotification.php`
- `RespectsPreferences`, `CatalogNotification`, the 4 own-toMail classes (`ItemAssigned`, `Mention`,
  `ItemStatusChanged`, `PageUpdated`), `ItemMentions` / `PageMentions` (pass the subject)
- mail component `mail::entries` (html + text), theme CSS, `BrandedMailMessage::entries()`
- `EmailPreviews` (digest preview), `routes/console.php` (prune)
- Docs: `.env.example`, `docs/self-hosting.md` ("Notification emails (digest)"),
  `docs-site/self-hosting/configuration.md`, `docs/legal/data-inventory.md` (§5), ARCHITECTURE /
  KNOWLEDGE / CONVENTIONS / `skills/add-notification.md`
