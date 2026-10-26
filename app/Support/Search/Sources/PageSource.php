<?php

namespace App\Support\Search\Sources;

use App\Support\Mcp\PageContentConverter;
use App\Support\Search\SearchDocument;
use App\Support\Search\SearchText;
use App\Support\Search\SearchTypes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Docs and whiteboards (not folders).
 *
 * - Readers see the published version, so the index holds `name` + `content`: the published doc,
 *   or the doc itself before its first publish. Autosaved `draft_content` / `draft_name` of a
 *   published doc is never indexed — it would leak unpublished text to readers.
 * - A doc that was never published (`status = draft`) is indexed with `is_draft`: only its author
 *   and project admins find it (PagePolicy::view, Page::visibleTo).
 * - Whiteboards: the title only (scenes can be megabytes of drawing data).
 */
class PageSource extends Source
{
    private const WHITEBOARD_PREFIX = '{"type":"excalidraw"%';

    public function type(): string
    {
        return SearchTypes::PAGE;
    }

    protected function idColumn(): string
    {
        return 'pages.id';
    }

    protected function base(): Builder
    {
        return DB::table('pages')
            ->join('projects', 'projects.id', '=', 'pages.project_id')
            ->where('pages.type', 'page')
            ->select(['pages.id', 'pages.name', 'pages.status', 'pages.created_by', 'pages.updated_at', 'pages.project_id', 'projects.workspace_id'])
            // whiteboard scenes stay in the database
            ->selectRaw('case when pages.content like ? then null else pages.content end as content', [self::WHITEBOARD_PREFIX])
            ->selectRaw('case when pages.content like ? then 1 else 0 end as is_whiteboard', [self::WHITEBOARD_PREFIX]);
    }

    protected function build(Collection $rows, array $ids): array
    {
        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $whiteboard = (bool) (int) $row->is_whiteboard;
            $documents[$id] = new SearchDocument(
                type: SearchTypes::PAGE,
                sourceId: $id,
                title: (string) $row->name,
                projectId: (int) $row->project_id,
                workspaceId: (int) $row->workspace_id,
                authorId: self::int($row->created_by),
                isDraft: $row->status === 'draft',
                subtype: $whiteboard ? 'whiteboard' : 'doc',
                body: $whiteboard ? null : self::text($row->content),
                updatedAt: self::time($row->updated_at),
            );
        }

        return $documents;
    }

    private static function text(?string $content): ?string
    {
        if ($content === null || $content === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return SearchText::fromHtml($content);
        }
        $html = PageContentConverter::pageContentToHtml($decoded);

        return $html !== '' ? SearchText::fromHtml($html) : SearchText::fromJson($decoded);
    }
}
