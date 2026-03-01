# Changelog

All notable changes to Brainitive are documented here.

---

## [1.4.0] – 2026-03-01

### Added

- **Helpdesk module** — Full helpdesk boards with columns, tickets, custom fields (EAV), and ticket contacts for external support requests.
- **Public support widget & embed** — Floating widget (script tag) and iframe embed so customers can submit tickets from your site; form includes configurable default fields (Name, Email, Subject, Message, Attachments) and custom fields with rich text support (Quill in embed).
- **Public ticket portal** — Branded portal page for contacts to view their ticket, description (rich HTML), conversation thread, and reply with rich text (Lexical); header with logo and Support portal badge, footer with Terms, Privacy, Cookies, Refund, and Cookie settings.
- **Attachments** — File upload on ticket creation (widget and embed) and attachment support in the flow; files uploaded after ticket creation via public API.
- **Email notifications** — Ticket created (with secure portal link) and ticket reply notifications to contacts and agents; reply notifications are queued.
- **Helpdesk settings** — General, Columns, Fields (default + custom with drag-and-drop), and Embed tab with copy-paste script and iframe snippets; default fields: Email and Subject fixed; Name, Message, and Attachments editable (label, required) or hideable.
- **Modern embed UI** — Card layout, CSS variables, focus rings, prose styling for description and reply bodies, loading spinner, and consistent form controls.

---

## [Unreleased] – 2026-02-28

### Fixed

- **Editor: content wiped on fast Edit → Save / Update** — Fixed a race condition in `RichLexicalEditorFull` and `RichLexicalEditor` where `HtmlOnChangePlugin` could fire `onChange` with an empty string while `InitialContentPlugin` was still clearing and repopulating the Lexical tree. Replaced the fragile "skip first N updates" counter with a shared `isInitializingRef` that is held `true` during the entire init update and released only after the `onUpdate` callback confirms the tree is fully written. This affected both the **Items description** (Edit/Save flow) and the **Pages document** (Edit/Update flow). ([`RichLexicalEditorFull.tsx`](resources/js/components/editor/RichLexicalEditorFull.tsx), [`RichLexicalEditor.tsx`](resources/js/components/editor/RichLexicalEditor.tsx))

- **Pages: Update button sent empty content** — `currentHtmlRef` was initialised to `null`, so clicking **Edit → Update** immediately (before any keystroke) called `handleExplicitUpdate` with an empty string, wiping the page. Fixed by initialising the ref with the current saved HTML. Also added an `isEditing` guard to `handleUpdateContent` to prevent spurious auto-saves triggered during the view→edit editor remount. ([`Pages/Show.tsx`](resources/js/Pages/Pages/Show.tsx))
