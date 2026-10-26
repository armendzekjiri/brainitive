# Branded email templates (BRAI-155)

**Branch:** feat/email-templates (into `release/v1.44.0`)
**Status:** done (pending lead review)
**Started:** 2026-10-26
**Board card:** BRAI-155 (item 360)

## Problem / goal
"Lets do better email templates that match our layouts! check every template." Today most mail goes
through Laravel's stock notification template (Laravel header, "Regards, Laravel", "Hello!"), four mails
have hand-made HTML (one still in the old purple), helpdesk mailbox replies are bare HTML, and the admin
test email is plain text. Every email should share one Brainitive layout that matches the app/homepage.

## Inventory (every email path)

Recipient: **member** = a signed-in user, mail is one of their notification channels (Profile →
Notifications); **guest** = customer / client / visitor (no preferences); **account** = transactional
mail to an account holder or invitee (no preferences).

| # | Class | Recipient | Template before | After |
|---|-------|-----------|-----------------|-------|
| 1–21 | `CatalogNotification::toMail` → AutomationNotice, ClientCommented, ClientDecision, FeedbackSubmitted, GithubSyncFailed, InvitationAccepted, ItemCommented, ItemDue, LeadAssigned, ProjectExportReady, ReviewCommented, ReviewDecided, ReviewRequested, SprintCompleted, SprintStarted, TaskAssigned, TicketSlaBreached, TicketSlaWarning, TicketStatusChanged, WebhookDisabled, WorkspaceRoleChanged | member | Laravel `notifications::email` | branded, member footer; title = headline, body in a quote panel, context as eyebrow |
| 22 | ItemAssignedNotification | member | Laravel default | branded member |
| 23 | ItemStatusChangedNotification | member | Laravel default | branded member |
| 24 | ItemReminderNotification | member | Laravel default | branded member |
| 25 | MentionNotification | member | Laravel default | branded member |
| 26 | PageUpdatedNotification | member | Laravel default | branded member |
| 27 | TaskDigestNotification | member | Laravel default | branded member |
| 28 | NewLeadNotification | member | Laravel default | branded member |
| 29 | TicketAssignedNotification | member | Laravel default | branded member |
| 30 | TicketCreatedNotification | member (watchers/admins) + **guest** (TicketContact) | Laravel default | branded; contact variant signed by the helpdesk |
| 31 | TicketReplyNotification | member + **guest** (TicketContact) | Laravel default | branded; contact variant signed by the helpdesk |
| 32 | TicketResolvedNotification | guest (TicketContact) | Laravel default | branded guest, helpdesk name |
| 33 | `TicketMailer` reply (helpdesk mailbox, threaded) | guest | bare `<div>` HTML | branded HTML part (raw rich text), text part unchanged |
| 34 | `TicketMailer` acknowledgment (email-created ticket) | guest | bare `<p>` HTML | branded HTML part, text part unchanged |
| 35 | LeadConfirmationNotification | guest (form visitor) | Laravel default | branded guest (body from form settings, no extra greeting) |
| 36 | PortalLoginLinkNotification | guest (portal identity) | Laravel default | branded guest |
| 37 | ClientPortalGuestNotification (invited / published / replied) | guest | Laravel default | branded guest |
| 38 | FeedbackStatusChangedNotification | guest (follower) | Laravel default | branded guest; unfollow link in the footer |
| 39 | BillingReportMail (Mailable, PDF attached) | guest (client) | `emails/billing-report*.blade.php` | markdown mail on the branded layout, text view kept |
| 40 | VerifyEmailNotification | account | `emails/verify-email.blade.php` (purple) | branded account mail |
| 41 | WorkspaceInvitationNotification | account (invitee) | `emails/workspace-invitation.blade.php` | branded account mail |
| 42 | Password reset (Laravel `ResetPassword`, URL already from APP_URL) | account | Laravel default | `ResetPassword::toMailUsing` → branded account mail |
| 43 | Admin → Settings "Send test email" | admin | `Mail::raw` plain text | branded `InstanceTestMail` |
| — | CalendarReminderNotification | member | push only (no `toMail`) | unchanged, not an email |

Inbound auto-replies: only the TicketMailer acknowledgment (#34). No other mail paths (`Mail::raw/send/to`) exist.

## Approach
- Publish + restyle Laravel's mail components (`resources/views/vendor/mail/{html,text}`) and the
  notification template (`resources/views/vendor/notifications/email.blade.php`); theme
  `resources/views/vendor/mail/html/themes/brainitive.css`, `mail.markdown.theme = brainitive`.
- `App\Notifications\Messages\BrandedMailMessage` (extends MailMessage): `member($notifiable, $type)`,
  `guest($notifiable, $reason)`, `account($notifiable, $reason)` + `headline / eyebrow / quote /
  preheader / context / footerLink / signOff`. Its extra data flows into the template via `data()`.
- `RespectsPreferences::brandedMail($notifiable, $type)` → member mail for Users, guest mail otherwise,
  so every preference-backed notification gets "Manage notifications" and guests never do.
- `Markdown::withSecuredEncoding()` (via `BrandedMail::register()`): echoed values can't form Markdown
  links/HTML anywhere in mail templates (defence in depth on top of the classes' `plain()`).
- Admin preview: `admin.emails.*` — list + iframe preview (rendered HTML from fake, unsaved models
  inside a rolled-back transaction) + "Send test to me" (throttled, admin's own address).

## Test plan
- PHPUnit `tests/Feature/Mail/EmailTemplatesTest.php`: every inventory entry renders (HTML + text),
  branded markers, no "Laravel", absolute https links, member vs guest footer, escaping.
- PHPUnit `tests/Feature/Admin/AdminEmailPreviewTest.php`: admin-only, each entry renders, test send.
- e2e `e2e/tests/email-templates.spec.ts` + auth-account / client-portal / feedback-portal /
  workspaces-projects regression.

## The layout
Grey page (#F4F6F8) → one white card (max 600 px, 16 px radius, 1 px border) with a 4 px blue→green accent
bar, the Brainitive logo on a white header band (light logo by default, `logo-dark.png` swapped in by
`@media (prefers-color-scheme: dark)` / Outlook.com `[data-ogsc]`; clients that only darken colours keep the
light logo on its white band), then: eyebrow (context, small caps) → H1 headline → "Hi <first name>," →
lines → quote panel (user text, blue left border) → button (#0B72C4 primary / #3F7D27 success / #C53030
error — all ≥ 4.9:1 with white) → "— Brainitive" (or the helpdesk / sender) → "Button not working?" subcopy.
Footer under the card: "Brainitive — <workspace / helpdesk / portal / instance>", a "why you got this"
line, "Manage notifications" (members only) and extra links (unfollow). Hidden preheader on every mail.
`color-scheme: light dark`, dark palette (#0F161D page, #17212B card, #D3DCE4 text), mobile rules at
600 / 480 px (tighter padding, full-width button). Fonts: Hanken Grotesk → Inter → system stack.

## Decisions
- **One template for all notifications**: the published `notifications::email` + `BrandedMailMessage`
  extras (headline / eyebrow / quote / preheader / context / reason / footerLink / signOff /
  withoutGreeting). A plain `MailMessage` still renders branded with the "account" footer.
- **Audience decides the footer**: `RespectsPreferences::brandedMail($notifiable, $type)` → member mail for a
  `User` (reason names the category: "email is on for “Comments” …" + Manage notifications →
  `profile.edit#notifications`), guest mail for anyone else (TicketContact, routed mail). Guests and
  invitees never see a preferences link.
- **`Markdown::withSecuredEncoding()`** globally (BrandedMail::register): every `{{ }}` echo in mail
  templates escapes `[ ] < > \`, so user text can't form Markdown links/images/HTML even where a class
  forgot `plain()`. Intentional Markdown in lines (`**bold**`, `- list`) still works; the one intentional
  link (feedback "Stop following this idea") moved to `footerLink()`.
- **Headline / eyebrow / footer are HTML-block components** (`mail::headline`, `mail::eyebrow`) — never
  Markdown-parsed, so titles keep their punctuation (no `plain()` stripping) and stay escaped. Text-part
  variants render them as plain lines.
- **Helpdesk customer mails are signed by the helpdesk**: `BrandedMail::helpdeskName()` = the mailbox
  `from_name` (the name TicketMailer already used) or the helpdesk name — eyebrow, sign-off and footer.
  From/Reply-To logic unchanged (TicketMailer's per-mailbox sender kept as is).
- **TicketMailer**: only the HTML part changed (raw layout, `:raw="true"`, stored reply HTML as before);
  the text part — what inbound threading/quote stripping reads — is byte-for-byte the same. Agent replies
  get no extra sign-off (agents sign themselves); acknowledgments do.
- **App name**: templates say "Brainitive"; `config('app.name')` only appears as the footer context and only
  when it isn't a default ("Laravel"/"Brainitive") — a misconfigured APP_NAME can't leak "Laravel".
  Subjects that used `config('app.name')` (verify, invitation, portal login) now say "Brainitive".
  (`MAIL_FROM_NAME` is still env/admin-settings driven — set it to "Brainitive" in production.)
- **Password reset**: `ResetPassword::toMailUsing` → branded account mail; URL still from the existing
  `createUrlUsing` (APP_URL).
- **Admin test email**: `Mail::raw` → `App\Mail\InstanceTestMail` (markdown, sent synchronously).
- **Logos**: the lead's 256-colour `public/images/email/logo(-dark).png` had no tRNS chunk (transparent
  pixels rendered black). Rebuilt at the same sizes as RGBA (alpha snapped to 8 levels): 18 / 17 KB.
  Displayed 220 px wide with explicit width/height.
- **Preview page** (Instance admin → Emails, `admin.emails.index|show|send`): `EmailPreviews` is the
  inventory (47 entries) — unsaved models with made-up people, rendered in a transaction that is always
  rolled back. The iframe is sandboxed (`allow-popups` only) and the preview response sets its own CSP
  (`default-src 'none'`, inline styles + images only, `frame-ancestors 'self'`). Light/Dark toggles the
  iframe's `color-scheme` (the email's `prefers-color-scheme` follows it). "Send test to me": the
  signed-in admin's address only, `[Test]` subject prefix, throttle 5/min, audit `emails.test_sent`.

## Review fixes
- security-reviewer: quoted user text was Markdown-parsed inside the panel — a code fence + blank lines
  could close the panel and render headings/bold in team mail. Quotes now use `mail::quote` (escaped,
  `nl2br`, never parsed; `BrandedMail::htmlText()`), billing message the same; billing sender/company are
  single lines; "Manage notifications" is built from APP_URL (`BrandedMail::appRoute()`), not the request
  host; backslashes in agent replies survive the secured-encoding post-processing (`rawHtml()`).
- frontend-reviewer: AdminShell flash toasts keyed on the flash object (a repeated "Test email sent"
  shows again); toggles are `aria-pressed` buttons in a `role=group`; `?scheme=light|dark` forces the
  preview's dark rules on/off (WebKit may not pass the frame's color-scheme on); dark table borders;
  `overflow-wrap:anywhere` in the body; the email list scrolls on phones; Desktop width is a real 600 px.

## Notes for the lead (docs to update — not edited here)
- **ARCHITECTURE** (mail): "Every email renders on the branded layout: `resources/views/vendor/mail/{html,text}`
  + `vendor/notifications/email.blade.php`, theme `vendor/mail/html/themes/brainitive.css`
  (`mail.markdown.theme`). Notifications build `App\Notifications\Messages\BrandedMailMessage`;
  helpers in `App\Support\Mail\BrandedMail`; inventory + previews in `App\Support\Mail\EmailPreviews`
  (Instance admin → Emails)."
- **CONVENTIONS**: "Every new mail uses the branded layout. Member notifications go through
  `CatalogNotification` (or `$this->brandedMail($notifiable, TYPE)` from `RespectsPreferences`) so they get
  'Manage notifications'; mail to customers/guests uses `BrandedMailMessage::guest($n, 'You get this email
  because …')`; Mailables use `markdown:` with `<x-mail::message>`. Add every new email to
  `EmailPreviews::entries()` — `EmailTemplatesTest` renders them all. Never put Markdown links into lines
  (secured encoding escapes them) — use `->action()` or `->footerLink()`."
- **KNOWLEDGE**: "`Markdown::withSecuredEncoding()` is on: `{{ }}` in mail templates escapes `[ ] < > \`.
  HTML blocks in mail Markdown end at a blank line — keep raw HTML (billing message) on lines without blank
  lines (nl2br). CssToInlineStyles drops @media rules from the theme: dark/mobile rules live in the
  layout's `<style>` with `!important`. InstanceMailConfig::apply() rebuilds the mailer — with the array
  mailer in tests, messages sent before it are gone."
- add-notification skill: mention `brandedMail()` + `EmailPreviews` entry.
- **Homepage (Welcome.tsx)**: per the release rule, the lead may add "Branded emails" to the v1.44 features.

## Changelog
- Every email Brainitive sends now has one branded look — logo, card layout, dark-mode and phone friendly,
  a clear "why you got this" footer and "Manage notifications" for members; helpdesk customers see your
  helpdesk's name. Instance admins can preview every email and send themselves a test (Admin → Emails).
