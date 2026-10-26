# feat/smart-links — Smart links in documents (BRAI-143, board item 317)

Release: v1.42.0. Branch `feat/smart-links` from `release/v1.42.0`.
**Status:** done

## Goal
Jira / Confluence-style smart links: paste the URL of an issue, doc / whiteboard, ticket or plan
of this workspace into a document (or an issue description) and it becomes a live chip (key,
title, status, due date) or a card (title, key, status, assignee, due date, priority, project).
Insert them from "/link" or the side "+" block menu too. The target shows the document / issue
under "Referenced by". Plus the bug "the plus in the side of documents doesn't work".

## Design
- **Saved HTML** (degrades everywhere: share page, client portal, MCP, exports, search):
  `<a href="<canonical url>" data-smart-link="item:12" data-smart-link-view="inline|card">WEB-12 Fix login</a>`.
  Refs: `item:<id>`, `page:<uuid>`, `ticket:<id>`, `plan:<id>` (+ alias `plan:<project-slug>/<plan-slug>`
  from a pasted plan URL; the endpoint answers with the canonical ref and the editor rewrites it).
- **Frontend:** `components/editor/nodes/SmartLinkNode.tsx` (inline DecoratorNode, importDOM on
  `a[data-smart-link]` above LinkNode), `components/editor/smartLinks/` (data store with one batched
  request per tick + cache per page load + refresh on focus, URL parser, picker dialog, plugin for
  paste / "/link" / "+" menu). Editor prop `smartLinks={{ workspaceSlug, projectSlug }}`; without it
  the node renders as a plain link (comments, tickets, public pages).
- **Backend:** `App\Support\References\SmartLinks` (parse refs from HTML, resolve a batch with the
  ContentReferences visibility rules, picker search across accessible projects of the workspace),
  `SmartLinkController` (`smart-links.index` batched GET, `smart-links.search`, `smart-links.projects`;
  ReadOnlySession, `throttle:…,smart-links`).
- **Backlinks:** derived `content_references` from `Page::saved` (docs, published `content`) and
  `Item::saved` (description), same project only; pruned when the link is removed.
- **"+" bug:** the handle portal unmounted when the pointer left the handle for the popover
  (`onMouseLeave` cleared `hoveredKey`). Keep the handle + popover mounted while the menu is open.

## Steps
1. Backend support + endpoint + hooks + tests.
2. SmartLinkNode + store + paste + picker + slash + "+" menu; wire Pages/Show and ItemDetail.
3. "+" fix (separate commit).
4. e2e `e2e/tests/smart-links.spec.ts`; focused PHPUnit + e2e + pint + tsc + build.

## Notes for docs (after merge)
See the final report: ARCHITECTURE (Linked content section), KNOWLEDGE (smart link refs, derived
sync from published content only).

## Status
Done on `feat/smart-links` (two commits: the "+" fix, then smart links). Focused gate green:
PHPUnit References + Pages (122), related suites (ClientPortal, Imports, Boards, Mcp, Helpdesk,
templates, mention notifications, Unit: 312), e2e smart-links + content + backlinks +
document-review + meeting-notes + quick-capture + boards + page-icons + client-portal (64 incl.
setup), pint, tsc, build. The quick-create dialog (GlobalCreateModal, `RichLexicalEditor.tsx`) is a
separate small editor: a pasted URL stays a plain link there.
