# Export a project and restore it

Brainitive can write one project to a ZIP file and restore that file as a **new** project, on the same
instance or another one (v1.41.0).

## Export
1. Open the project → **Settings** → **General** → **Export project** (project admins and workspace admins).
2. The export is prepared in the background. You get a notification when it's ready, and the card shows
   a **Download** button.
3. The download link works for **24 hours** and only for you. After that the file is deleted; start a new
   export when you need one.

The ZIP contains boards, issues (with subtasks, labels, custom fields, comments, attachments and links),
docs, folders and whiteboards, task lists, plans, releases, sprints and the helpdesk structure.

It never contains members and roles, helpdesk tickets and contacts, leads, time entries, history, integrations,
webhooks, automations, share links or tokens. `manifest.json` lists what was left out and why.

## Restore
1. Account menu → **Import data** → **Brainitive project export**. You need permission to create projects.
2. Upload the ZIP. Before anything is created, Brainitive checks the format version and the checksum of
   every file. A changed or incomplete file is refused.
3. Check the preview: what's inside, the new project's name and key (the key must be free), and who is who.
   People are matched to members of this workspace by email; pick someone else or "Nobody" for each.
   Mapped people are added to the new project as editors. Nobody is invited.
4. **Restore as a new project.** Issues get new keys; links, subtasks, the page tree, whiteboard issue cards and
   plan rows point at the new copies. Comments show their original author ("Originally by …").

A restore that stops halfway can be retried from the import page; it continues without duplicating anything.

## Format
`manifest.json` has `format: "brainitive-project"` and `version: 1`, plus the sha256 checksum of every file.
The format is described in `app/Support/Imports/Archive/ProjectArchive.php`.
