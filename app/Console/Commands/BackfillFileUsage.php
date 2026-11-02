<?php

namespace App\Console\Commands;

use App\Models\EditorUpload;
use App\Models\Workspace;
use App\Support\Entitlements\WorkspaceUsageMeter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Codex web review C (v1.49.0): files stored before the accounting (workspace logos and headers, bookmark icons and
 * files, project and item-type icons, lead-form images, whiteboard previews and embeds) get their `editor_uploads`
 * row, so WorkspaceUsageMeter counts them and replacing / deleting them gives their bytes back. Only files that
 * still exist on the public disk and have no row yet; nothing is deleted or moved.
 *
 * Dry run by default (reports what it would record per kind); `--force` records them and re-measures the touched
 * workspaces. Safe to run again. Run once after deploying v1.49.0.
 */
class BackfillFileUsage extends Command
{
    protected $signature = 'files:backfill-usage {--force : record them (without it: only report)}';

    protected $description = 'Record stored workspace files (logos, icons, bookmark files …) uploaded before v1.49.0 so they count against file storage';

    public function handle(WorkspaceUsageMeter $meter): int
    {
        $disk = Storage::disk('public');
        $known = EditorUpload::query()->pluck('path')->flip();
        $found = [];
        foreach ($this->candidates() as [$workspaceId, $path, $kind]) {
            $path = ltrim(str_starts_with($path, '/storage/') ? substr($path, 9) : $path, '/');
            if ($path === '' || str_contains($path, '..') || isset($known[$path]) || isset($found[$path]) || ! $disk->exists($path)) {
                continue;
            }
            $found[$path] = ['workspace_id' => $workspaceId, 'path' => $path, 'size' => (int) $disk->size($path), 'kind' => $kind];
        }

        if ($found === []) {
            $this->info('Every stored workspace file is already counted.');

            return self::SUCCESS;
        }

        $byKind = collect($found)->groupBy('kind')->map(fn ($rows, $kind) => [$kind, $rows->count(), number_format($rows->sum('size'))]);
        $this->table(['kind', 'files', 'bytes'], $byKind->values()->all());

        if (! $this->option('force')) {
            $this->warn(count($found).' file(s) not counted yet. Dry run: nothing changed. Run again with --force to record them.');

            return self::SUCCESS;
        }

        foreach (array_chunk(array_values($found), 500) as $chunk) {
            EditorUpload::query()->insertOrIgnore(array_map(fn ($row) => $row + ['created_at' => now(), 'updated_at' => now()], $chunk));
        }
        Workspace::whereIn('id', array_unique(array_column($found, 'workspace_id')))->get()->each(fn (Workspace $w) => $meter->measure($w));
        $this->info('Recorded '.count($found).' file(s) and re-measured their workspaces.');

        return self::SUCCESS;
    }

    /** @return iterable<array{0: int, 1: string, 2: string}> [workspace id, path, kind] */
    private function candidates(): iterable
    {
        foreach (DB::table('workspaces')->select('id', 'logo', 'header_image')->cursor() as $w) {
            if ($w->logo) {
                yield [$w->id, $w->logo, 'workspace_logo'];
            }
            if ($w->header_image) {
                yield [$w->id, $w->header_image, 'workspace_header'];
            }
        }
        foreach (DB::table('bookmarks')->where('icon', 'like', 'bookmark-icons/%')->select('workspace_id', 'icon')->cursor() as $b) {
            yield [$b->workspace_id, $b->icon, 'bookmark_icon'];
        }
        foreach (DB::table('bookmark_items as i')->join('bookmarks as b', 'b.id', '=', 'i.bookmark_id')->whereNotNull('i.file_path')->select('b.workspace_id', 'i.file_path')->cursor() as $i) {
            yield [$i->workspace_id, $i->file_path, 'bookmark_file'];
        }
        foreach (DB::table('projects')->where('icon', 'like', 'project-icons/%')->select('workspace_id', 'icon')->cursor() as $p) {
            yield [$p->workspace_id, $p->icon, 'project_icon'];
        }
        foreach (DB::table('item_types as t')->join('projects as p', 'p.id', '=', 't.project_id')->where('t.icon', 'like', 'project-item-type-icons/%')->select('p.workspace_id', 't.icon')->cursor() as $t) {
            yield [$t->workspace_id, $t->icon, 'item_type_icon'];
        }
        $disk = Storage::disk('public');
        foreach (DB::table('lead_forms as f')->join('projects as p', 'p.id', '=', 'f.project_id')->select('p.workspace_id', 'f.uuid')->cursor() as $f) {
            foreach ($disk->files('lead-forms/'.$f->uuid) as $path) {
                yield [$f->workspace_id, $path, 'lead_form_image'];
            }
        }
        foreach (DB::table('pages as pg')->join('projects as p', 'p.id', '=', 'pg.project_id')->select('p.workspace_id', 'pg.id', 'pg.content')->cursor() as $page) {
            foreach ($disk->files('excalidraw-embeds/'.$page->id) as $path) {
                yield [$page->workspace_id, $path, 'whiteboard_image'];
            }
            $preview = json_decode((string) $page->content, true)['previewImageUrl'] ?? null;
            if (is_string($preview) && str_contains($preview, '/storage/excalidraw-previews/')) {
                yield [$page->workspace_id, 'excalidraw-previews/'.basename((string) parse_url($preview, PHP_URL_PATH)), 'whiteboard_image'];
            }
        }
    }
}
