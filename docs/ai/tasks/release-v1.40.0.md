# Release v1.40.0: Automate (BRAI-63, BRAI-123, BRAI-79, BRAI-124, BRAI-125)

## Goal (the user's choices, 2026-10-24)
Direction "Automate". Email-to-helpdesk arrives through **both** transports: an IMAP mailbox per helpdesk
and a forwarding address on a provider webhook.

Process rule: the user is testing dev meanwhile and may add fixes to this release. **Agents run focused
tests only** (their new and affected specs and test classes, plus pint, tsc and build). No full PHPUnit or e2e
suites on agent branches. The lead runs the full gate once, after asking the user.

## Tickets, branches, ownership

| # | Card | Branch | Owns |
|---|---|---|---|
| 1 | BRAI-63 Workflow automation | `feat/automation` | `automation_rules` / `automation_runs`, `app/Support/Automation/*`, `AutomationController`, `Pages/Automations/*`, its section of `routes/automation.php`, its block in `routes/console.php`, how `DomainEvents` reaches the automation engine |
| 2 | BRAI-123 Recurring issues | `feat/recurring-issues` | `recurring_issues` table/model, `app/Support/RecurringIssues/*`, its route section, its console block, the board header's "Recurring" entry |
| 3 | BRAI-79 Email → helpdesk (IMAP) | `feat/helpdesk-imap` | **`InboundMail::receive()`** (the shared pipeline), inbound dedupe table, mailbox settings + encrypted credentials, IMAP poller command, threaded outgoing replies (Message-ID / In-Reply-To / References, routing token), `components/helpdesk/email/ImapMailboxSection.tsx` |
| 4 | BRAI-124 Email → helpdesk (forwarding address) | `feat/helpdesk-forwarding` | the inbound webhook route + controller (signature check) → `InboundMessage` → `InboundMail::receive()`, per-helpdesk forwarding address, `config/services.php` inbound keys, `components/helpdesk/email/ForwardingAddressSection.tsx` |
| 5 | BRAI-125 Helpdesk SLA | `feat/helpdesk-sla` | SLA policy per helpdesk, ticket SLA timestamps (first response, due, breached), breach checker command, `components/helpdesk/HelpdeskSlaTab.tsx`, SLA badges on tickets |

Shared files (keep edits small and self-contained, the lead merges): `HelpdeskEmailTab.tsx` (tickets 3+4 each
render their own section in it), `TicketController` (3 and 5), `Ticket` model (3 and 5), `NotificationCatalog::TYPES`,
`types/index.d.ts`, `routes/console.php`, `routes/automation.php`, `HelpdeskController::edit` props, `docs/ai/*`.

## Foundation (on `release/v1.40.0`)
- **`routes/automation.php`:** required inside `projects/{project:slug}` with `scopeBindings()`, one section per
  ticket (1, 2, 5).
- **`routes/console.php`:** a v1.40.0 block with one comment slot per ticket (1, 2, 3, 5). Every scheduled command
  is `->withoutOverlapping()`.
- **Inbound email contract:** `app/Support/Helpdesks/Inbound/InboundMessage.php` (readonly value object) and
  `InboundMail::receive(Helpdesk, InboundMessage): string` returning `RESULT_*`. It is a **stub until ticket 3
  lands**. Ticket 4 builds an `InboundMessage` from the provider payload and calls it; its tests may fake the
  pipeline until ticket 3 is merged into the release, and the lead re-runs ticket 4's tests after.
- **Helpdesk settings tabs:** `Pages/Helpdesks/Edit.tsx` has new **Email** and **SLA** tabs that render
  `components/helpdesk/HelpdeskEmailTab.tsx` and `HelpdeskSlaTab.tsx` (stubs). `?tab=email` and `?tab=sla` work.
  Tickets 3 and 4 each add their own section component under `components/helpdesk/email/` and mount it in
  `HelpdeskEmailTab` (one line each). Sections load and save through their own endpoints.

## Rules for all tickets
- **Scheduler reality:** dev (staging) runs `QUEUE_CONNECTION=sync` and **no scheduler**. Every scheduled job is
  an artisan command that is safe to run by hand and idempotent. e2e triggers it through an e2e-only route
  (`APP_ENV=e2e` only, like the existing e2e helpers) or tests the UI part only. Production has the scheduler.
- **Permissions:**
  - automation rules: project admins (whoever may edit project settings);
  - recurring issues: whoever can create issues on that board;
  - helpdesk email and SLA settings: whoever may edit the helpdesk (`helpdesks.update` policy).
  - Members get 403 on endpoints they can't use, and the UI hides them.
- **Secrets:** IMAP/SMTP passwords and webhook signing keys are cast `encrypted`, `$hidden`, and never sent
  back to the browser after saving (show "saved", allow replace). Never log them.
- **Outbound HTTP / sockets:** IMAP and SMTP hosts typed by users must not reach private networks. Reuse
  `OutboundUrl`'s IP checks for host + port (no private / link-local / loopback, except when `APP_ENV=e2e` or
  local with an explicit config flag that is off in production).
- **Accessible names stay stable.** Use `exact: true` for generic button names in e2e: sidebar rows have
  "Pin <name>" buttons.
- New notification types extend `app/Notifications/CatalogNotification.php` (contract keys) and are added to
  `NotificationCatalog::TYPES`. Automation-created changes keep notifying through the existing paths
  (`ItemObserver` → `Assignments::item()`).
- Every ticket ships PHPUnit + e2e and updates `docs/ai/ARCHITECTURE.md` / `KNOWLEDGE.md`. The lead writes the
  changelog and homepage.
- New frontend files go under lowercase `resources/js/components/…` (on disk the folder is `Components/`,
  macOS is case-insensitive). Stage them **by explicit path written in lowercase**
  (`git add resources/js/components/x/New.tsx`), then check with `git ls-files | grep New.tsx`. **Never** run
  `git -c core.ignorecase=false add -A` / `add .`: it stages the whole on-disk `Components/` folder as
  duplicates. (`ImportPathCaseTest` catches wrong-case paths.)

## Ticket specs

### 1. BRAI-63 Workflow automation (see the card for the researched scope)
- **Per-project rules:** WHEN trigger, IF conditions, THEN ordered actions. Rules start disabled and are
  versioned; each runs as a saved actor whose permissions are checked again at run time.
- **Triggers:**
  - item created; item moved (optionally to a given column); item assigned; comment added;
  - ticket created;
  - **due date passed** (scheduled, once per item and due date).
- **Conditions:** board or helpdesk, item type, priority, label, assignee empty / is X, destination column,
  title contains.
- **Actions:**
  - assign (a member or "the reporter"), move to a column on the same board;
  - set priority / type / due date (+N days) / add label;
  - add a comment (text with `{{item.key}}`, `{{item.title}}`);
  - notify a member or the assignee (new catalog type `automation_notice`, category `status`);
  - create a linked issue from a ticket on a board of the same project.
- **Not in v1:** arbitrary HTTP (webhooks exist), cross-project actions, nested branches.
- **Hook points:**
  - Today `DomainEvents::emit` returns early when no webhook listens. Ticket 1 makes the engine see events
    too, one cheap check for "any enabled rule for this project and trigger", without breaking the webhook
    tests.
  - Changes made by automations don't trigger rules again (origin flag). Bounded action count per run.
- **Runs:** after commit, queued; idempotent per rule version + event id; per-action result; a run log shows
  success / skipped / error.
- **UI:** project page → Tools and project settings link to **Automations**:
  - a list with on/off switch and last run;
  - a form-based builder (WHEN / IF / THEN cards);
  - "Test on an issue" dry run with no writes;
  - the run history.
- **Tests:**
  - PHPUnit: each trigger, condition and action; idempotency; no loops; permission loss; rollback means no
    run; dry run writes nothing.
  - e2e: create rule "issue created on Main Board → assign to Max + priority High", create an issue, see it
    assigned, see the run log.

### 2. BRAI-123 Recurring issues
- **A schedule per template:**
  - daily; weekly on chosen weekdays; every N weeks; monthly on day N or on the first/last weekday;
    yearly;
  - start date, optional end date or count, and the time zone of the creator.
- **The template:**
  - title (supports `{{date}}`), description, type, priority, assignee, labels;
  - target column (default: first column);
  - due date = occurrence + N days;
  - sprint: none (backlog).
- **Running it:** `app:create-recurring-issues` creates the due occurrences. It is idempotent per (template,
  occurrence date); after downtime it creates only the latest missed one, not a flood. Created issues link back
  to their template (badge "Repeats weekly").
- **UI:**
  - board header menu → **Recurring issues**: list, create/edit (with a preview of the next 5 dates),
    pause/resume, delete;
  - an issue's "Make this recurring" action pre-fills the template.
- **Tests:**
  - PHPUnit: schedule maths (DST, month ends, 31st → last day, leap years), idempotency, catch-up, pause,
    permissions.
  - e2e: create a weekly template, trigger the command through the e2e route, see the card on the board with
    the badge.

### 3. BRAI-79 Email → helpdesk via IMAP (and the shared pipeline)
- **Settings** (Email tab → "Connected mailbox"):
  - IMAP host / port / encryption / username / password, folder (default INBOX), the address customers
    write to;
  - SMTP for replies (or "send replies through the app mailer from that address");
  - "Test connection"; enable / disable; last check time and last error.
- **Poller:** `app:poll-helpdesk-mailboxes` every minute. It fetches UNSEEN mail, marks it seen (or moves it
  to a processed folder), and keeps per-message state so a crash never creates duplicates. Size limits
  apply. Use a maintained PHP IMAP library (check what composer can use without the deprecated ext-imap;
  prefer `webklex/php-imap` or `ddeboer/imap`) and document the choice.
- **`InboundMail::receive()`:**
  - dedupe on (helpdesk, Message-ID);
  - ignore auto-replies, bounces and lists (`auto-submitted`, `precedence: bulk|junk|list`, `x-autoreply`,
    `list-id`, mailer-daemon);
  - thread through In-Reply-To / References matched to our outgoing Message-IDs, **plus** a routing token
    (e.g. `+t<token>` in Reply-To or a hidden token in the subject); subject alone is never enough;
  - only the ticket's contact (or a CC already on the ticket) may reply into it; otherwise reject, or start a
    new ticket when the sender is new;
  - new sender: a ticket with a contact (existing `TicketContact` matching);
  - body: text, or sanitized HTML (`SafeHtml`), with quoted history stripped for replies;
  - safe attachments through the existing ticket attachment rules (`Uploads`).
- **Outgoing:** agent public replies to email tickets go out by mail with proper threading headers and a
  stable Message-ID stored on the reply. Internal notes never send. Existing contact notifications must not
  send twice.
- **Tests:**
  - PHPUnit with `.eml` fixtures: new ticket, reply threading, forged reply from an outsider, duplicate,
    auto-reply loop, multipart / HTML / attachments, oversized, connection errors.
  - e2e: settings UI save and "Test connection" (fake through an e2e flag), plus a ticket created from a
    fixture through an e2e-only route that feeds an `.eml` to `receive()`.

### 4. BRAI-124 Email → helpdesk via forwarding address
- **The address:** each helpdesk gets `<slug>-<random>@<INBOUND_MAIL_DOMAIN>` (`config('services.inbound_mail.domain')`).
  It can be regenerated, and the old one stops working.
- **Webhook:** `POST /api/inbound-mail/{provider}` for Postmark (JSON, basic-auth / token) and Mailgun (form +
  HMAC signature with timestamp, replay window). It verifies the signature, maps the recipient to a helpdesk,
  builds an `InboundMessage` (attachments to temp files) and calls `InboundMail::receive()`. It answers quickly
  with 200 / 4xx (heavy work queued) and is throttled.
- **Off by default:** without `INBOUND_MAIL_DOMAIN` and a provider secret, the section explains the setup
  (MX records, provider route) and the endpoint answers 404. Document self-hosting in docs.
- **UI** (Email tab → "Forwarding address"): the address with copy, "regenerate", and setup steps for Gmail /
  Outlook forwarding.
- **Tests:**
  - PHPUnit: signature valid / invalid / replayed, unknown recipient, payload mapping (fixtures for both
    providers), disabled = 404.
  - e2e: the settings section shows and copies the address when enabled in the e2e env.

### 5. BRAI-125 Helpdesk SLA
- **Policy per helpdesk:**
  - first-response and resolution targets per priority (minutes / hours / days), or none;
  - business hours (weekdays + from/to + time zone) or 24/7;
  - pause while the ticket is in a "waiting on customer" column (pick columns).
- **Per ticket:** `first_responded_at` (first public agent reply), `sla_first_response_due_at`,
  `sla_resolution_due_at`, `sla_breached_at` (and which). Recomputed when priority, policy or the pause state
  changes.
- **UI:**
  - ticket card and detail badges ("Respond in 2h", "Breached 3h ago", green "Met");
  - helpdesk board and list sort/filter by SLA;
  - Insights → Helpdesk gets breach counts and met % (small, self-contained addition to
    `app/Support/Insights` helpdesk tab).
- **Notifications:** `app:check-helpdesk-sla` sends `sla_warning` (before the breach, e.g. at 80 %) and
  `sla_breached`, category `helpdesk`, to the assignee, or the helpdesk admins when unassigned. Each is sent
  once per ticket and target.
- **Tests:**
  - PHPUnit: business-hours maths (overnight, weekends, DST), pause/resume, recompute, notifications once.
  - e2e: set a policy, create a ticket, see the badge; trigger the checker through an e2e-only route; see
    "Breached".
