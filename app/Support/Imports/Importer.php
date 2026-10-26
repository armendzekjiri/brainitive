<?php

namespace App\Support\Imports;

use App\Models\ImportRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

/**
 * One import source (v1.41.0). Register the class in ImportRegistry::SOURCES.
 * Contract: docs/ai/tasks/release-v1.41.0.md.
 *
 * Lifecycle (driven by ImportController + RunImport, never by the importer itself):
 *   upload → preview() (dry run: validate everything, write NOTHING but the returned array)
 *   → user confirms options (optionRules) → run() in a queued job, inside ImportScope
 *   (bulk notifications, webhooks and automations are off) → completed / failed.
 *
 * run() must be resumable: create every record through ImportMappings::once(), so a retried or
 * resumed run finds what it already made and never duplicates it. Throw ImportFailed for problems
 * the user can fix; anything else is reported as a generic failure.
 */
interface Importer
{
    /** Stable key stored on the run: trello | jira | notion | brainitive. */
    public static function source(): string;

    /** Shown on the import page, e.g. "Trello board (JSON)". */
    public static function label(): string;

    /** One sentence: what to export from the other tool, and what comes over. */
    public static function description(): string;

    /** Accept attribute for the file input, e.g. ".json,application/json". */
    public static function accept(): string;

    /** Laravel rules for the uploaded file (size limit, extension). Key: none, the rules only. */
    public static function fileRules(): array;

    /** true: the user picks an existing target project; false: the importer creates one (restore). */
    public static function needsProject(): bool;

    /** May this user import this source into the target? (project editor / workspace admin…) */
    public static function authorize(User $user, Workspace $workspace, ?Project $project): bool;

    /**
     * Validate the stored file and describe what would happen. NO writes except temp files under
     * the run's own private folder. Returns an importer-defined, JSON-serializable array the
     * frontend preview component renders (counts, mapping candidates, unsupported content).
     *
     * @throws ImportFailed
     */
    public function preview(ImportRun $run, string $path): array;

    /** Validation rules for the confirm step's `options` (mappings, include flags). */
    public function optionRules(ImportRun $run): array;

    /**
     * Do the import. Use $progress for counts / warnings; return links to what was created, e.g.
     * ['project_id' => 1, 'board_id' => 2] (stored as the run's `result`).
     *
     * @throws ImportFailed
     */
    public function run(ImportRun $run, string $path, ImportProgress $progress): array;
}
