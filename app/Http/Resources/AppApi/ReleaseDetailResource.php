<?php

namespace App\Http\Resources\AppApi;

use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\Release;
use App\Support\Boards\IssueMarkdown;
use App\Support\SafeHtml;
use Illuminate\Http\Request;

/**
 * One release (`GET /releases/{id}`): the list fields + description, its notes (the working copy and what is on
 * the public page, as Markdown and sanitized HTML), its issues (up to 500, as the web) and what the user may do.
 *
 * @mixin Release
 */
class ReleaseDetailResource extends ReleaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $release = $this->resource;
        $project = $release->project;
        $items = $release->items()
            ->with(['column:id,board_id,name,position', 'board:id,slug,name', 'itemType:id,name,slug,icon,color', 'assignee:id,name,avatar'])
            ->orderBy('key')->limit(500)
            ->get(['id', 'key', 'title', 'board_id', 'column_id', 'item_type_id', 'assigned_to', 'completed_at', 'parent_id']);
        $firstColumns = BoardColumn::whereIn('board_id', $items->pluck('board_id')->unique())->orderBy('position')
            ->get(['id', 'board_id'])->unique('board_id')->pluck('id', 'board_id');

        return [
            ...parent::toArray($request),
            'description' => $release->description,
            // the notes as edited (Markdown + sanitized HTML); null when there are none
            'notes_markdown' => IssueMarkdown::toMarkdown($release->notes),
            'notes_html' => SafeHtml::clean($release->notes),
            // what the public release page shows (null until published)
            'published_notes_markdown' => IssueMarkdown::toMarkdown($release->published_notes),
            // the notes changed since they were published
            'notes_changed' => $release->isPublished() && $release->notes !== $release->published_notes,
            'public_url' => $release->isPublished() ? route('releases.public', $release->public_token) : null,
            'items' => ReleaseItemResource::collection($items->map(fn (Item $i) => [
                'id' => $i->id,
                'key' => $i->key,
                'title' => $i->title,
                'state' => $i->completed_at ? 'done' : ($firstColumns->get($i->board_id) === $i->column_id ? 'todo' : 'in_progress'),
                'status' => $i->column?->name,
                'type' => $i->itemType?->only(['name', 'icon', 'color']),
                'assignee' => $i->assignee,
                'is_subtask' => $i->parent_id !== null,
                'url' => $i->board ? route('boards.show', [$project->workspace->slug, $project->slug, $i->board->slug]).'#item-'.$i->id : null,
            ])->values()),
            'can' => [
                // planning, shipping, notes, publishing: web only
                'manage' => $request->user()->can('update', $release),
            ],
        ];
    }
}
