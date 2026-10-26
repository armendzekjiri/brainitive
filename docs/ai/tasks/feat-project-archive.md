# feat/project-archive: portable project export + restore (BRAI-81, v1.41.0 ticket 4)

Card: BRAI-81 (main-board item 183). Contract: [`release-v1.41.0.md`](./release-v1.41.0.md) §4.

## Plan
- **Export** (project admins = `ProjectPolicy::update`):
  - Project settings → General → **Export project** card (`components/projects/ExportProjectCard.tsx`).
  - `POST projects.export.store` creates a `project_exports` row and queues `ExportProject`
    (after the response on the sync queue).
  - `ProjectArchiveWriter` builds the ZIP on the private `local` disk under `exports/<uuid>/`, with
    `manifest.json` (format `brainitive-project`, version 1, sha256 per file, counts, omitted) plus one
    JSON per module and the attachment / media files.
  - Done → `export_ready` (`ProjectExportReadyNotification`, category `workspace`) with a signed link
    that expires after 24 h. The download needs the signature, the exporter's session and an unexpired
    row.
  - Files are deleted after 24 h (`exports:cleanup` schedule, and when the next export starts). Rows
    are pruned after 30 days.
- **Restore:** `ProjectArchiveImporter` (source `brainitive`).
  - `preview()` = SafeZip extract into the run folder → `ProjectArchiveReader` (manifest, version,
    every checksum, no unlisted files, JSON limits) → counts, people (email → workspace member), and a
    free name / key.
  - `run()` creates everything through `ImportMappings::once` (archive refs as source ids). Order:
    project, members, types, priorities, labels, fields, boards, columns, sprints, releases, issues
    (parents first), comments, attachments, links, pages (parents first), task lists, plans, helpdesks.
    Then it rewrites references (parents, item links, whiteboard issue cards, plan rows, media URLs).
- **Content safety:** HTML through `SafeHtml` on restore. Mentions become text on export. Whiteboard
  links and app links are only kept when they're http(s). Media come only from inside the archive.
- **Tests:** `tests/Feature/Imports/ProjectArchiveTest.php`, `e2e/tests/project-archive.spec.ts`.

## Status
- [x] backend export, [x] restore, [x] UI, [x] PHPUnit, [x] e2e, [x] docs (ARCHITECTURE, KNOWLEDGE, docs/project-export.md)
