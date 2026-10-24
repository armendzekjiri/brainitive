<?php

namespace App\Support\MeetingNotes;

use App\Models\Board;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Boards\ProjectIssues;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\RequestIdConflict;
use App\Support\References\ContentReferences;

/**
 * Creates the reviewed action items as issues (BRAI-62): all of them or none (one transaction),
 * and once per idempotency key — a retry after a lost response replays the first result instead
 * of creating the cards again (`IdempotentRequest`, tool `meeting-notes.create`).
 *
 * The caller authorizes board + source first, on every call (also replays): a key never outlives
 * a permission. The notes themselves are never written.
 */
class ActionItemCreator
{
    public const TOOL = 'meeting-notes.create';

    /**
     * @param  list<array{title: string, description?: string|null, excerpt?: string|null, assigned_to?: int|null, due_date?: string|null}>  $actions
     * @return array{count: int, items: list<array{id: int, key: string, title: string, url: string}>, board_url: string, replayed: bool}
     *
     * @throws RequestIdConflict
     */
    public static function create(User $user, Workspace $workspace, Project $project, Board $board, ?Page $source, bool $linkSource, string $idempotencyKey, array $actions): array
    {
        $payload = [
            'board_id' => $board->id,
            'page_id' => $source?->id,
            'link_source' => $linkSource,
            'actions' => $actions,
        ];

        $run = IdempotentRequest::run($user, self::TOOL, $idempotencyKey, $payload, function () use ($user, $workspace, $project, $board, $source, $linkSource, $actions) {
            $issues = array_map(fn (array $action) => [
                'title' => $action['title'],
                'description' => self::description($action, $linkSource, $workspace, $project, $source),
                'due_date' => $action['due_date'] ?? null,
                'assigned_to' => $action['assigned_to'] ?? null,
            ], $actions);

            $items = ProjectIssues::create($board, $project, $user, $issues);

            if ($linkSource && $source) {
                $items->each(fn (Item $item) => ContentReferences::link($project, $user, $item, $source));
            }

            return [
                'count' => $items->count(),
                'items' => $items->map(fn (Item $item) => [
                    'id' => $item->id,
                    'key' => $item->key,
                    'title' => $item->title,
                    'url' => route('boards.items.show', [$workspace->slug, $project->slug, $board->slug, $item->id]),
                ])->values()->all(),
                'board_url' => route('boards.show', [$workspace->slug, $project->slug, $board->slug]),
            ];
        });

        return $run['result'] + ['replayed' => $run['replayed']];
    }

    /** The edited description, plus (optionally) the evidence quote and where it came from. */
    private static function description(array $action, bool $linkSource, Workspace $workspace, Project $project, ?Page $source): ?string
    {
        $html = collect(preg_split("/\n{2,}/", trim((string) ($action['description'] ?? ''))) ?: [])
            ->filter(fn (string $p) => trim($p) !== '')
            ->map(fn (string $p) => '<p>'.nl2br(e(trim($p)), false).'</p>')
            ->implode('');

        $excerpt = trim((string) ($action['excerpt'] ?? ''));
        if ($linkSource && $excerpt !== '') {
            $html .= '<blockquote>'.e($excerpt).'</blockquote>';
            $html .= $source
                ? '<p>From the meeting notes: <a href="'.e(route('pages.show', [$workspace->slug, $project->slug, $source->uuid])).'">'.e($source->name).'</a></p>'
                : '<p>From the meeting notes.</p>';
        }

        return $html === '' ? null : $html;
    }
}
