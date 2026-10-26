<?php

namespace App\Support\Imports\Notion;

use App\Models\ImportRun;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Imports\Importer;
use App\Support\Imports\ImportMappings;
use App\Support\Imports\ImportProgress;
use App\Support\Mcp\PageContentConverter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Notion "Markdown & CSV" ZIP → a page tree in the chosen project (BRAI-66, v1.41.0).
 *
 * - The tree: every Notion page becomes a page; a page with subpages becomes a folder holding the
 *   page itself first, then its subpages. A database (CSV) becomes a table page (and a folder when
 *   its rows have pages). Folders without a page of their own stay folders.
 * - Two passes: first every folder and page is created (ImportMappings::once, source types
 *   `notion_folder` / `notion_page`, id = Notion id), then each page's content is written, with
 *   links between pages rewritten to the new URLs via ImportMappings::map. Writing a page's
 *   content is itself recorded (`notion_content`), so a retry never rewrites a page twice.
 * - Pages stay drafts (author + admins see them); folders are visible to the project, like any
 *   folder. Nothing is published.
 * - Images and files inside the archive are stored like editor uploads (NotionAssets); nothing
 *   outside the archive is ever fetched.
 */
final class NotionImporter implements Importer
{
    public static function source(): string
    {
        return 'notion';
    }

    public static function label(): string
    {
        return 'Notion (Markdown & CSV)';
    }

    public static function description(): string
    {
        return 'Export a page or workspace from Notion as "Markdown & CSV" (with subpages) and upload the ZIP. Pages, subpages, images and files come over as draft pages; databases come over as tables.';
    }

    public static function accept(): string
    {
        return '.zip,application/zip';
    }

    public static function fileRules(): array
    {
        return ['extensions:zip', 'max:204800']; // 200 MB
    }

    public static function needsProject(): bool
    {
        return true;
    }

    public static function authorize(User $user, Workspace $workspace, ?Project $project): bool
    {
        return $project !== null && $user->can('create', [Page::class, $project]);
    }

    public function preview(ImportRun $run, string $path): array
    {
        $archive = NotionArchive::open($path, dirname($path));
        $problems = ['broken' => [], 'unsupported' => []];
        $assets = new NotionAssets($archive, $run->uuid);
        $content = new NotionContent(
            $archive,
            fn () => '#',
            fn (string $file, bool $image) => $assets->url($file, $image),
            function (string $kind, string $page, string $target) use (&$problems) {
                $problems[$kind][] = ['page' => $page, 'target' => $target];
            },
        );

        $counts = ['pages' => 0, 'databases' => 0, 'folders' => 0, 'empty_pages' => 0, 'images' => 0, 'files' => 0];
        foreach ($archive->ordered() as $node) {
            $counts[$node->kind() === 'database' ? 'databases' : ($node->kind() === 'page' ? 'pages' : 'folders')]++;
            if ($archive->isEmpty($node)) {
                $counts['empty_pages']++;
            }
            $content->html($node); // dry run: resolves every link and file, collects the problems
        }
        ['images' => $counts['images'], 'files' => $counts['files']] = $assets->accepted();

        return [
            'tree' => $this->tree($archive, $archive->roots),
            'counts' => $counts,
            'broken_links' => array_slice($problems['broken'], 0, 50),
            'broken_link_count' => count($problems['broken']),
            'skipped_files' => array_slice($problems['unsupported'], 0, 50),
            'unsupported' => array_filter([
                'Database views, formulas and relations (databases come over as tables)' => $counts['databases'],
                'Files of types the editor doesn’t accept' => count($problems['unsupported']),
                'Links to files or pages missing from the export' => count($problems['broken']),
            ]),
            'folders' => $this->targetFolders($run->project),
        ];
    }

    public function optionRules(ImportRun $run): array
    {
        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('pages', 'id')->where('project_id', $run->project_id)->where('type', 'folder')],
            'skip_empty' => ['boolean'],
        ];
    }

    public function run(ImportRun $run, string $path, ImportProgress $progress): array
    {
        $archive = NotionArchive::open($path, dirname($path));
        $project = $run->project()->with('workspace')->firstOrFail();
        $options = $run->options ?? [];
        $skipEmpty = (bool) ($options['skip_empty'] ?? true);
        $parentId = isset($options['parent_id'])
            ? Page::query()->where('project_id', $project->id)->where('type', 'folder')->whereKey($options['parent_id'])->value('id')
            : null;
        if (isset($options['parent_id']) && $parentId === null) {
            $progress->warn('The chosen parent folder no longer exists, so the pages were imported at the top of the project.');
        }

        $nodes = $archive->ordered();
        $progress->total(count($nodes) * 2);

        // pass 1: the tree
        $next = (int) (Page::query()->where('project_id', $project->id)->where('parent_id', $parentId)->max('position') ?? -1) + 1;
        $roots = [];
        foreach ($archive->roots as $key) {
            $created = $this->createNode($run, $archive, $progress, $project, $key, $parentId, $next++, $skipEmpty);
            if ($created !== null) {
                $roots[] = $created;
            }
        }

        // pass 2: content, with links to the pages created above
        $pageIds = ImportMappings::map($run, 'notion_page');
        $folderIds = ImportMappings::map($run, 'notion_folder');
        $uuids = Page::query()->whereIn('id', [...array_values($pageIds), ...array_values($folderIds)])->pluck('uuid', 'id');
        $url = fn (int $id) => isset($uuids[$id]) ? route('pages.show', [$project->workspace->slug, $project->slug, $uuids[$id]], false) : null;

        $assets = new NotionAssets($archive, $run->uuid, dirname($path).'/notion-stored.json');
        $content = new NotionContent(
            $archive,
            fn (string $key) => isset($pageIds[$key]) ? $url($pageIds[$key]) : (isset($folderIds[$key]) ? $url($folderIds[$key]) : null),
            fn (string $file, bool $image) => $assets->url($file, $image),
            function (string $kind, string $page, string $target) use ($progress) {
                if ($kind === 'broken') {
                    $progress->warn("“{$page}”: “{$target}” isn’t in the export, so it was kept as text.");
                    $progress->unsupported('Links to files or pages missing from the export');
                } else {
                    $progress->warn("“{$page}”: “{$target}” is a type of file the editor doesn’t accept, so it wasn’t imported.");
                    $progress->unsupported('Files of types the editor doesn’t accept');
                }
            },
        );

        foreach ($nodes as $node) {
            $page = isset($pageIds[$node->key]) ? Page::query()->find($pageIds[$node->key]) : null;
            if ($page !== null) {
                // once per page: a retry neither rewrites a page (maybe edited since) nor reports it twice
                ImportMappings::once($run, 'notion_content', $node->key, Page::class, function () use ($page, $node, $content, $progress) {
                    $page->update(['content' => PageContentConverter::htmlToPageContent($content->html($node))]);
                    if ($node->kind() === 'database') {
                        $progress->unsupported('Database views, formulas and relations (databases come over as tables)');
                    }
                    $progress->save(); // with the page, in one transaction: a retry doesn't count it twice

                    return $page;
                });
            }
            $progress->advance();
        }
        // files are counted once, at the end, from the ledger of every attempt
        foreach ($assets->accepted() as $what => $count) {
            if ($count > 0) {
                $progress->created($what, $count);
            }
        }

        $first = count($roots) === 1 ? $roots[0] : null;
        $landing = $first ?? ($parentId !== null ? Page::find($parentId) : ($roots[0] ?? null));

        return [
            'page_id' => $landing?->id,
            'url' => $landing ? route('pages.show', [$project->workspace->slug, $project->slug, $landing->uuid], false) : null,
        ];
    }

    /**
     * Create a node (and its subtree). A node with children becomes a folder holding its own page
     * first. Returns the top record created for it, or null when it was skipped.
     */
    private function createNode(ImportRun $run, NotionArchive $archive, ImportProgress $progress, Project $project, string $key, ?int $parentId, int $position, bool $skipEmpty): ?Page
    {
        $node = $archive->nodes[$key];
        $empty = $archive->isEmpty($node);
        $hasPage = $node->kind() !== 'folder' && ! ($skipEmpty && $empty);

        if ($node->children === []) {
            $progress->advance();
            if (! $hasPage) {
                if ($empty) {
                    $progress->warn("“{$node->title}” was empty and was skipped.");
                }

                return null;
            }

            return $this->page($run, $progress, $project, $node, $parentId, $position);
        }

        $folder = $this->record($run, $progress, 'notion_folder', $project, $node, $parentId, $position, 'folder');
        $progress->advance();
        $childPosition = 0;
        if ($hasPage) {
            $this->page($run, $progress, $project, $node, $folder->id, $childPosition++);
        }
        foreach ($node->children as $child) {
            $this->createNode($run, $archive, $progress, $project, $child, $folder->id, $childPosition++, $skipEmpty);
        }

        return $folder;
    }

    private function page(ImportRun $run, ImportProgress $progress, Project $project, NotionNode $node, ?int $parentId, int $position): Page
    {
        return $this->record($run, $progress, 'notion_page', $project, $node, $parentId, $position, $node->kind() === 'database' ? 'database' : 'page');
    }

    private function record(ImportRun $run, ImportProgress $progress, string $sourceType, Project $project, NotionNode $node, ?int $parentId, int $position, string $kind): Page
    {
        return ImportMappings::once($run, $sourceType, $node->key, Page::class, function () use ($run, $progress, $project, $node, $parentId, $position, $kind) {
            $page = Page::create([
                'project_id' => $project->id,
                'parent_id' => $parentId,
                'created_by' => $run->user_id,
                'last_edited_by' => $run->user_id,
                'name' => mb_substr($node->title, 0, 255),
                'slug' => Str::limit(Str::slug($node->title), 200, '') ?: ($kind === 'folder' ? 'folder' : 'page'),
                'type' => $kind === 'folder' ? 'folder' : 'page',
                'icon' => match ($kind) {
                    'folder' => '📁',
                    'database' => '🗂️',
                    default => '📄',
                },
                'content' => $kind === 'folder' ? null : PageContentConverter::htmlToPageContent(''),
                'position' => $position,
                'status' => 'draft',
            ]);
            $progress->created(match ($kind) {
                'folder' => 'folders',
                'database' => 'tables',
                default => 'pages',
            });
            $progress->save(); // in the same transaction as the record and its mapping

            return $page;
        });
    }

    /** The preview tree (title, kind, empty, rows for tables). */
    private function tree(NotionArchive $archive, array $keys): array
    {
        return array_map(function (string $key) use ($archive) {
            $node = $archive->nodes[$key];

            return array_filter([
                'key' => $key,
                'title' => $node->title,
                'kind' => $node->kind(),
                'empty' => $archive->isEmpty($node),
                'rows' => $node->csv !== null ? max(0, count($archive->rows($node)) - 1) : null,
                'children' => $node->children === [] ? null : $this->tree($archive, $node->children),
            ], fn ($value) => $value !== null);
        }, $keys);
    }

    /** Folders of the target project the user can put the import into, with their path. */
    private function targetFolders(?Project $project): array
    {
        if ($project === null) {
            return [];
        }
        $all = Page::query()->where('project_id', $project->id)->get(['id', 'parent_id', 'name', 'type'])->keyBy('id');
        $folders = [];
        foreach ($all as $page) {
            if ($page->type !== 'folder') {
                continue;
            }
            $names = [];
            for ($current = $page, $depth = 0; $current !== null && $depth < 20; $current = $all[$current->parent_id] ?? null, $depth++) {
                array_unshift($names, $current->name);
            }
            $folders[] = ['id' => $page->id, 'path' => implode(' / ', $names)];
        }
        usort($folders, fn ($a, $b) => strnatcasecmp($a['path'], $b['path']));

        return array_slice($folders, 0, 500);
    }
}
