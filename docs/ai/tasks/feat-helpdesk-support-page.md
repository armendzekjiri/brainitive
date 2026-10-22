# Helpdesk: agent ticket form, resolve message, support page, notification texts

**Branch:** feat/helpdesk-support-page
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-56 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-158

## Problem / goal
- Agents created tickets with one subject input and no customer, so the customer could not be emailed, and the support form's required fields were ignored.
- Resolving a ticket sent the customer nothing.
- There was no shareable support link, only embed snippets.
- Ticket notifications showed as "moved".

## Changes
- `TicketController::store`: customer email required; the form's required rules apply (name/message settings, custom fields by type); assignee must be a workspace member (also on update); optional email to the customer. UI: `components/helpdesk/NewTicketDialog.tsx`, opened from the header and from each column.
- `TicketController::move` + `ResolveTicketDialog`: moving into a done column asks for a closing message, saves it as a reply and sends `TicketResolvedNotification` (mail). Cancel = no move.
- `helpdesks.public_slug` (unique) + `/support/{public_slug}` → `Widgets/HelpdeskEmbed` with a branded `page` header. Set in Helpdesk settings → Embed.
- `components/NotificationMessage.tsx` renders all 8 notification types for the bell and `/notifications`.

## Test plan
`tests/Feature/Helpdesk/HelpdeskAgentTest.php`: required customer and fields, workspace-only assignee, resolve message + email, support page, ticket links. Browser: the dialog validates and creates, dragging to Resolved opens the popup and saves, notifications read "opened/replied", the support page renders without login, settings save the address.
