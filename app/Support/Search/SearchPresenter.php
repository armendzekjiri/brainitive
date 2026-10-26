<?php

namespace App\Support\Search;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Index hits → results people can open (BRAI-55): the URL, a label, where it lives ("Website ·
 * Main Board", "Comment on WEB-1 · Homepage hero"). Names and slugs are read live in one query per
 * kind of record on the page (never per result), so renames show at once and nothing goes stale.
 * A hit whose record vanished since it was indexed is dropped.
 */
final class SearchPresenter
{
    private const LABELS = [
        SearchTypes::ITEM => 'Issue',
        SearchTypes::COMMENT => 'Comment',
        SearchTypes::PAGE => 'Doc',
        SearchTypes::TICKET => 'Ticket',
        SearchTypes::PLAN => 'Plan',
        SearchTypes::TASK => 'Task',
        SearchTypes::SNIPPET => 'Snippet',
        SearchTypes::BOOKMARK => 'Bookmark',
        SearchTypes::CAPTURE => 'Capture',
        SearchTypes::ATTACHMENT => 'File',
        SearchTypes::TICKET_ATTACHMENT => 'File',
    ];

    /**
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    public static function present(array $hits): array
    {
        if ($hits === []) {
            return [];
        }

        $ids = [];
        foreach ($hits as $hit) {
            $ids[$hit['type']][] = $hit['source_id'];
        }
        $of = fn (string $type) => $ids[$type] ?? [];

        // comments and files point at their issue / ticket through container_id
        $parents = [];
        foreach ($hits as $hit) {
            $parents[$hit['type']][$hit['source_id']] = $hit['container_id'];
        }
        $parentOf = fn (string $type, int $id) => $parents[$type][$id] ?? null;
        $parentIds = fn (string $type) => array_values(array_filter($parents[$type] ?? []));
        $itemIds = array_merge($of(SearchTypes::ITEM), $parentIds(SearchTypes::COMMENT), $parentIds(SearchTypes::ATTACHMENT));
        $ticketIds = array_merge($of(SearchTypes::TICKET), $parentIds(SearchTypes::TICKET_ATTACHMENT));

        $items = self::rows($itemIds, fn ($in) => DB::table('items')->join('boards', 'boards.id', '=', 'items.board_id')
            ->whereIn('items.id', $in)->get(['items.id', 'items.key', 'items.title', 'boards.slug as board_slug', 'boards.name as board_name']));
        $tickets = self::rows($ticketIds, fn ($in) => DB::table('tickets')->join('helpdesks', 'helpdesks.id', '=', 'tickets.helpdesk_id')
            ->whereIn('tickets.id', $in)->get(['tickets.id', 'tickets.key', 'tickets.subject', 'helpdesks.slug as helpdesk_slug', 'helpdesks.name as helpdesk_name']));
        $pages = self::rows($of(SearchTypes::PAGE), fn ($in) => DB::table('pages')->whereIn('id', $in)->get(['id', 'uuid']));
        $plans = self::rows($of(SearchTypes::PLAN), fn ($in) => DB::table('plans')->whereIn('id', $in)->get(['id', 'slug']));
        $tasks = self::rows($of(SearchTypes::TASK), fn ($in) => DB::table('task_list_items')->join('task_lists', 'task_lists.id', '=', 'task_list_items.task_list_id')
            ->whereIn('task_list_items.id', $in)->get(['task_list_items.id', 'task_lists.slug as list_slug', 'task_lists.name as list_name']));
        $snippets = self::rows($of(SearchTypes::SNIPPET), fn ($in) => DB::table('snippets')->join('snippet_collections as c', 'c.id', '=', 'snippets.collection_id')->join('workspaces as w', 'w.id', '=', 'c.workspace_id')
            ->whereIn('snippets.id', $in)->get(['snippets.id', 'c.slug as collection_slug', 'c.name as collection_name', 'w.slug as workspace_slug', 'w.name as workspace_name']));
        $bookmarks = self::rows($of(SearchTypes::BOOKMARK), fn ($in) => DB::table('bookmark_items')->join('bookmarks as b', 'b.id', '=', 'bookmark_items.bookmark_id')->join('workspaces as w', 'w.id', '=', 'b.workspace_id')
            ->whereIn('bookmark_items.id', $in)->get(['bookmark_items.id', 'bookmark_items.url', 'b.slug as bookmark_slug', 'b.name as bookmark_name', 'w.slug as workspace_slug', 'w.name as workspace_name']));
        $captures = self::rows($of(SearchTypes::CAPTURE), fn ($in) => DB::table('captures')->whereIn('id', $in)->get(['id', 'text', 'archived_at', 'processed_at']));

        $projectIds = array_values(array_unique(array_filter(array_column($hits, 'project_id'))));
        $projects = self::rows($projectIds, fn ($in) => DB::table('projects')->join('workspaces as w', 'w.id', '=', 'projects.workspace_id')
            ->whereIn('projects.id', $in)->get(['projects.id', 'projects.name', 'projects.slug', 'w.slug as workspace_slug', 'w.name as workspace_name']));

        $results = [];
        foreach ($hits as $hit) {
            $type = $hit['type'];
            $id = $hit['source_id'];
            $project = $hit['project_id'] ? ($projects[$hit['project_id']] ?? null) : null;
            $p = $project ? [$project->workspace_slug, $project->slug] : null;

            $issue = fn (?int $itemId) => $itemId !== null && $p && isset($items[$itemId]) ? $items[$itemId] : null;
            $ticket = fn (?int $ticketId) => $ticketId !== null && $p && isset($tickets[$ticketId]) ? $tickets[$ticketId] : null;
            $issueUrl = fn (object $i) => route('boards.show', [...$p, $i->board_slug]).'#item-'.$i->id;
            $ticketUrl = fn (object $t) => route('tickets.show', [...$p, $t->helpdesk_slug, $t->id]);

            [$url, $context, $fallbackTitle, $external] = match ($type) {
                SearchTypes::ITEM => ($i = $issue($id)) ? [$issueUrl($i), $project->name.' · '.$i->board_name, null, null] : [null, null, null, null],
                SearchTypes::COMMENT => ($i = $issue($parentOf(SearchTypes::COMMENT, $id))) ? [$issueUrl($i), $project->name.' · '.$i->key, 'Comment on '.$i->key.' · '.$i->title, null] : [null, null, null, null],
                SearchTypes::ATTACHMENT => ($i = $issue($parentOf(SearchTypes::ATTACHMENT, $id))) ? [$issueUrl($i), $project->name.' · '.$i->key.' · '.$i->title, null, null] : [null, null, null, null],
                SearchTypes::TICKET => ($t = $ticket($id)) ? [$ticketUrl($t), $project->name.' · '.$t->helpdesk_name, null, null] : [null, null, null, null],
                SearchTypes::TICKET_ATTACHMENT => ($t = $ticket($parentOf(SearchTypes::TICKET_ATTACHMENT, $id))) ? [$ticketUrl($t), $project->name.' · '.$t->key.' · '.$t->subject, null, null] : [null, null, null, null],
                SearchTypes::PAGE => $p && isset($pages[$id]) ? [route('pages.show', [...$p, $pages[$id]->uuid]), $project->name, null, null] : [null, null, null, null],
                SearchTypes::PLAN => $p && isset($plans[$id]) ? [route('plans.show', [...$p, $plans[$id]->slug]), $project->name, null, null] : [null, null, null, null],
                SearchTypes::TASK => $p && isset($tasks[$id]) ? [route('task-lists.show', [...$p, $tasks[$id]->list_slug]), $project->name.' · '.$tasks[$id]->list_name, null, null] : [null, null, null, null],
                SearchTypes::SNIPPET => isset($snippets[$id]) ? [route('snippets.show', [$snippets[$id]->workspace_slug, $snippets[$id]->collection_slug]), $snippets[$id]->workspace_name.' · '.$snippets[$id]->collection_name, null, null] : [null, null, null, null],
                SearchTypes::BOOKMARK => isset($bookmarks[$id]) ? [
                    route('bookmarks.show', [$bookmarks[$id]->workspace_slug, $bookmarks[$id]->bookmark_slug]),
                    $bookmarks[$id]->workspace_name.' · '.$bookmarks[$id]->bookmark_name,
                    null,
                    is_string($bookmarks[$id]->url) && preg_match('~^https?://~i', $bookmarks[$id]->url) ? $bookmarks[$id]->url : null,
                ] : [null, null, null, null],
                SearchTypes::CAPTURE => isset($captures[$id]) ? [
                    route('captures.index', ['state' => self::captureState($captures[$id]), 'q' => mb_substr(SearchText::plain($captures[$id]->text), 0, 40)]),
                    'Your captures (private)', null, null,
                ] : [null, null, null, null],
                default => [null, null, null, null],
            };
            if ($url === null) {
                continue; // gone since it was indexed
            }

            $label = self::LABELS[$type];
            if ($type === SearchTypes::PAGE && $hit['subtype'] === 'whiteboard') {
                $label = 'Whiteboard';
            }
            $titleHtml = $hit['title_html'] !== '' ? $hit['title_html'] : htmlspecialchars((string) $fallbackTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $results[] = [
                'id' => "{$type}:{$id}",
                'type' => $type,
                'group' => SearchTypes::groupOf($type),
                'label' => $label,
                'source_id' => $id,
                'container_id' => $hit['container_id'],
                'key' => $hit['key'],
                'title' => html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'title_html' => $titleHtml,
                'snippet_html' => $hit['snippet_html'],
                'url' => $url,
                'external_url' => $external,
                'context' => $context,
                'project' => $project ? ['id' => (int) $project->id, 'name' => $project->name] : null,
                'workspace' => $project->workspace_name ?? ($type === SearchTypes::SNIPPET ? $snippets[$id]->workspace_name : ($type === SearchTypes::BOOKMARK ? $bookmarks[$id]->workspace_name : null)),
                'status' => $hit['status'],
                'is_draft' => $hit['is_draft'],
                'updated_at' => $hit['updated_at'] ? CarbonImmutable::parse($hit['updated_at'])->toIso8601String() : null,
            ];
        }

        return $results;
    }

    private static function captureState(object $capture): string
    {
        return $capture->archived_at !== null ? 'archived' : ($capture->processed_at !== null ? 'processed' : 'inbox');
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private static function rows(array $ids, \Closure $query): array
    {
        $ids = array_values(array_unique($ids));

        return $ids === [] ? [] : $query($ids)->keyBy(fn ($row) => (int) $row->id)->all();
    }
}
