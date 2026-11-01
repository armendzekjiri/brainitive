<?php

namespace App\Support\Imports\Archive;

use App\Support\AppVersion;

/**
 * The portable project archive (v1.41.0, BRAI-81): a ZIP written by ProjectArchiveWriter and
 * restored as a NEW project by ProjectArchiveImporter. Documented in docs/project-export.md.
 *
 * Layout (format "brainitive-project", version 1):
 *
 *   manifest.json     { format, version, app_version, created_at, project: {name, key},
 *                       counts: {…}, files: {"<path>": "<sha256>"}, omitted: [{what, count, why}] }
 *   project.json      name, key, description, icon, color, disabled_type_slugs, item_types,
 *                     priorities, labels, fields (+ options)
 *   people.json       [{ref, name, email}]: everyone the content points at (metadata only)
 *   boards.json       boards (+ columns, swimlanes, sprints), releases
 *   issues.json       issues (+ comments, attachments, custom field values), links
 *   pages.json        folders, docs and whiteboards, parents first
 *   task_lists.json   task lists (+ tasks)
 *   plans.json        plans (rows linked to issues by ref)
 *   helpdesks.json    helpdesk structure: columns, form fields, form texts (no tickets / contacts)
 *   attachments/…     issue attachment files
 *   media/…           images and files embedded in descriptions, comments and docs
 *
 * Ids never appear: every object has an archive-local `ref` ("i12"), references use refs. Types and
 * priorities shared by the whole instance are referenced as "slug:<slug>". Embedded media are
 * "brainitive-media:<ref>" placeholders. Every file except the manifest is listed in `files` with
 * its sha256; restore refuses a missing, changed or unlisted file.
 */
final class ProjectArchive
{
    public const FORMAT = 'brainitive-project';

    public const VERSION = 1;

    /** Fallback only: the release that introduced this format. The running version comes from AppVersion (BRAI-433). */
    public const APP_VERSION = '1.41.0';

    public const MODULES = ['project.json', 'people.json', 'boards.json', 'issues.json', 'pages.json', 'task_lists.json', 'plans.json', 'helpdesks.json'];

    public const MAX_ISSUES = 20000;

    public const MAX_PAGES = 5000;

    public const MAX_TASKS = 20000;

    /** Attachment + media files in one archive (SafeZip refuses more than 5000 entries). */
    public const MAX_FILES = 4500;

    public const MAX_FILE_BYTES = 50 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 480 * 1024 * 1024;

    /** One module file; a preview decodes them one at a time in the web request (memory_limit ≤ 512 MB). */
    public const MAX_JSON_BYTES = 48 * 1024 * 1024;

    /** SafeZip limits for a restore upload. */
    public const ZIP_LIMITS = [
        'max_entries' => 5000,
        'max_total_bytes' => 512 * 1024 * 1024,
        'max_file_bytes' => self::MAX_FILE_BYTES,
        'max_depth' => 4,
    ];

    /** Placeholder for an embedded media file inside content. */
    public const MEDIA_SCHEME = 'brainitive-media:';

    /**
     * What an archive never contains, and why. Counts are added per export.
     *
     * @var array<string, string> what => why
     */
    public const NEVER = [
        'Members and roles' => 'Memberships are not exported. Restore maps people to members of the target workspace by email.',
        'Helpdesk tickets and contacts' => 'Personal data of your customers. Only the helpdesk structure is exported.',
        'Lead forms and leads' => 'Personal data of the people who sent them.',
        'Time tracking and billing' => 'Time entries belong to the workspace’s billing.',
        'History, watchers and page versions' => 'Only the current state is exported.',
        'Integrations, webhooks and automations' => 'Tokens, secrets and connections to other services never leave the instance.',
        'Share links and public tokens' => 'Public links are not portable and would expose the restored copy.',
        'Saved board views, recurring issues and sticky notes' => 'Personal or scheduled settings, not project content.',
        'Snippets and bookmarks' => 'They belong to the workspace, not to a project.',
        'Unpublished changes of published docs' => 'A published doc is exported as readers see it.',
    ];

    public static function appVersion(): string
    {
        return AppVersion::current() ?? self::APP_VERSION;
    }
}
