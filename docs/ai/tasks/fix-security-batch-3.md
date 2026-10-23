# Security hardening, batch 3: XSS & uploads

**Branch:** fix/security-batch-3
**Status:** done
**Started:** 2026-10-23

## Changes
- Uploads: extension allowlist (`Uploads::SAFE`), everything else `.bin`; `.xhtm` added to the
  active list; Caddy sandbox CSP regex covers xhtm / mht.
- Page export (PDF, HTML, Markdown) sanitizes the stored HTML (`safeHtml`).
- TweetNode: numeric ids only, anchor built with `setAttribute`.
- MCP markdown → HTML: links only http(s), mailto, relative.
- Project icon: a stored path must be the project's own `project-icons/{id}/icon.*`; only that
  file is ever deleted.
- Public ticket replies / attachments: throttled, max 5 files per reply, max 20 visitor
  attachments per ticket, refused when the helpdesk has attachments turned off.
- Ticket emails: visitor subject as plain text (no Markdown links).
- Bookmark links: no javascript: / data: / vbscript: / file: / blob: URLs.

## Tests
`tests/Feature/Security/SecurityRegressionTest.php` (batch 3 section).
