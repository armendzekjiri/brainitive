# Linked content and backlinks (BRAI-71)

**Branch:** feat/backlinks
**Status:** done
**Started:** 2026-10-24
**Board card:** https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-173

## Problem / goal
Show related work next to each resource (issues, docs / whiteboards, helpdesk tickets, plans) so
people can move between modules without copying descriptions around. Every resource gets a
"Related content" section (what it links to) and a "Referenced by" section (what links to it).

Existing relations stay where they are: `ItemLink` (issue ↔ issue), plan rows linked to issues
(`row.itemId`, `PlanIssueSync`) and whiteboard issue cards (`customData.itemId`). The new
relation never replaces or duplicates them: issue ↔ issue stays an `ItemLink`, plan rows and
whiteboard cards show up as **derived**, read-only references.

## Approach
- **One table, `content_references`** — `project_id`, `source_type` / `source_id`,
  `target_type` / `target_id`, `origin` (`manual` | `derived`), `created_by`, timestamps.
  Types are stable short keys (`item`, `page`, `ticket`, `plan`), not class names. Unique
  `(source_type, source_id, target_type, target_id, origin)`: a repeated manual link is a no-op,
  and a manual + derived pair for the same endpoints is shown **once** (origins merged).
  Direction matters: "A links to B" shows B under A's *Related content* and A under B's
  *Referenced by*.
- **Scoped to one project.** Both endpoints must be in the reference's project (foreign ids →
  422). No `workspace_id` (project moves keep working, see KNOWLEDGE). No cross-workspace.
- **Rules:** no self-links; issue → issue is refused (use issue links); folders aren't
  endpoints (pages of type `page` only — docs and whiteboards).
- **Derived references** are written by model hooks, so every write path is covered (editor
  save, MCP `update_plan`, version restore, duplicate, templates):
  - `Plan::saved` (created or `data` changed) → `PlanIssueSync::linkedIds()` → derived
    plan → item rows.
  - `Page::saved` (whiteboard, created or `content` changed) → issue cards (not deleted
    elements) → derived page → item rows.
  - `ContentReferences::syncDerived()` diffs: inserts missing (`insertOrIgnore`), deletes the
    ones no longer present, restricted to issues of the same project. Removing the row link /
    card removes the derived backlink; the UI never edits the plan or whiteboard.
- **Visibility (batched, no N+1):** the index endpoint authorizes the open resource with its
  policy, loads all references of it in one query, then loads the other endpoints per type in
  one query each, applying the same rules the policies apply inside the project: pages through
  `visibleTo($user)` (drafts → author + admins), issues / tickets / plans not trashed and their
  board / helpdesk not trashed. Anything not visible is dropped before counting, so titles,
  counts and the picker's search never leak a private draft.
- **Writes:** add / remove manual references needs `update` on the source and `view` on the
  target (a target the user can't see answers 422 "not found", same as a foreign id — no
  existence leak). Derived references can't be removed here (422).
- **Delete / archive / restore / transfer:**
  - soft deletes (issues, tickets, plans, boards, helpdesks): rows stay, the endpoint is hidden;
    `restore()` brings the reference back unchanged.
  - archived pages stay visible with an "Archived" badge.
  - hard deletes prune: `Page::deleted` (also children deleted by the FK cascade, via a
    project-wide prune of missing pages), `forceDeleted` on Item / Ticket / Plan.
  - an issue moved to another project (`moveItemToBoard`) drops its references (and its
    subtasks') in the old project.
  - project transfer: nothing to do — references hang off `project_id`; URLs are built from the
    current workspace slug; detached members lose access with the project.
- **Canonical URLs:** the same as `ResourceLocator` (extended with plans and tickets): issue =
  `boards.show#item-{id}`, doc / whiteboard = `pages.show`, ticket = `tickets.show`, plan =
  `plans.show`.

## Changes
- Migration `2026_10_25_110000_create_content_references_table.php` (additive, SQLite-safe).
- Model `ContentReference` (types, origins, `project()`, `creator()`); `Project::contentReferences()`.
- `App\Support\References\ContentReferences`: `resolve`, `link`, `forEndpoint` (batched
  visibility + DTOs), `candidates` (picker search), `syncDerived`, `whiteboardItemIds`,
  `forget`, `pruneMissingPages`.
- Hooks: `Plan` / `Page` saved → derived sync; `Page` deleted, `Item` / `Ticket` / `Plan`
  forceDeleted → prune; `BoardController::moveItemToBoard` → forget in the old project.
- `ContentReferenceController` + routes under `projects/{project}` (scopeBindings):
  `references.index` (GET, ReadOnlySession), `references.candidates` (GET, ReadOnlySession),
  `references.store` (POST, throttled), `references.destroy` (DELETE `{contentReference}`).
- `ResourceLocator`: plan + ticket locations.
- Frontend: `components/references/RelatedContent.tsx` (sections + "Link related content"
  picker dialog), types in `types/index.d.ts`; mounted in `ItemDetail` (after Linked issues),
  `Pages/Pages/Show.tsx` (docs + whiteboards), the ticket dialog in `Helpdesks/Show.tsx`, and a
  "Related" sheet on `Plans/Show.tsx`.

## Test plan
- PHPUnit `tests/Feature/References/ContentReferencesTest.php`: link issue → doc and the
  backlink shows; repeat doesn't duplicate; self-link / issue → issue / folder / unsupported
  types / foreign project ids fail; viewer can't add; outsider 403; private draft hidden for an
  editor (list, counts, picker) but visible for its author; unpublish hides again; derived from
  plan rows + whiteboard cards, shown once with manual; removing the row / card removes it;
  derived can't be deleted; soft delete hides + restore brings back; page delete / child
  cascade prunes; force delete prunes; move to another project drops; project transfer keeps
  and URLs follow the new workspace; constant query count for 2 vs 12 references; canonical
  URLs equal `ResourceLocator`.
- E2E `e2e/tests/backlinks.spec.ts` (+ `e2e/helpers/backlinks.ts`): issue → doc shows
  "Referenced by"; linking twice doesn't duplicate; removing updates both sides; plan row /
  whiteboard card show once as derived; the member doesn't see a private draft's title / count.

## Docs to update
ARCHITECTURE (new "Linked content" module entry), KNOWLEDGE (derived references come from
model hooks; visibility mirrors policies in batch), tasks/README index.

## Open questions
- MCP tools for references and global search over references are out of scope (non-goals).
