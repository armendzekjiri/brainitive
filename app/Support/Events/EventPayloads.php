<?php

namespace App\Support\Events;

use App\Models\BillingReport;
use App\Models\Board;
use App\Models\Comment;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The `data` of webhook events (BRAI-104): plain arrays with ids, keys, titles, URLs, status names
 * and people as {id, name}. No emails of members, no secrets, no rates; amounts only on
 * billing_report.* (admins configure webhooks).
 *
 * Builders never load relations on the caller's model (that would leak into its JSON response or
 * keep stale relations): they work on a copy of its attributes.
 */
final class EventPayloads
{
    public const EXCERPT = 1000;

    /** Workspace of an item in one query (the observer must stay cheap when nobody listens). */
    public static function itemWorkspaceId(Item $item): ?int
    {
        $id = DB::table('boards')->join('projects', 'projects.id', '=', 'boards.project_id')
            ->where('boards.id', $item->board_id)->value('projects.workspace_id');

        return $id ? (int) $id : null;
    }

    public static function projectWorkspaceId(?int $projectId): ?int
    {
        $id = $projectId ? Project::withTrashed()->whereKey($projectId)->value('workspace_id') : null;

        return $id ? (int) $id : null;
    }

    public static function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    public static function project(?Project $project): ?array
    {
        return $project ? ['id' => $project->id, 'key' => $project->key, 'name' => $project->name, 'slug' => $project->slug] : null;
    }

    public static function item(Item $item): array
    {
        /** @var Item $copy */
        $copy = self::copy($item, ['board.project.workspace', 'column:id,name,is_done_column', 'assignee:id,name', 'reporter:id,name', 'itemType:id,name', 'priority:id,name']);
        $board = $copy->board;
        $project = $board?->project;

        return [
            'id' => $copy->id,
            'key' => $copy->key,
            'title' => $copy->title,
            'url' => self::itemUrl($copy),
            'type' => $copy->itemType?->name,
            'priority' => $copy->priority?->name,
            'status' => $copy->column?->name,
            'completed' => $copy->completed_at !== null,
            'assignee' => self::person($copy->assignee),
            'reporter' => self::person($copy->reporter),
            'parent_id' => $copy->parent_id,
            'sprint_id' => $copy->sprint_id,
            'story_points' => $copy->story_points,
            'start_date' => $copy->start_date?->toDateString(),
            'due_date' => $copy->due_date?->toDateString(),
            'board' => $board ? ['id' => $board->id, 'name' => $board->name, 'slug' => $board->slug] : null,
            'project' => self::project($project),
            'created_at' => $copy->created_at?->toIso8601String(),
            'updated_at' => $copy->updated_at?->toIso8601String(),
            'completed_at' => $copy->completed_at?->toIso8601String(),
        ];
    }

    public static function comment(Comment $comment, Item $item): array
    {
        return [
            'item' => self::item($item),
            'comment' => [
                'id' => $comment->id,
                'parent_id' => $comment->parent_id,
                'reply' => $comment->parent_id !== null,
                'excerpt' => self::excerpt($comment->body),
                'url' => self::itemUrl($item),
                'created_at' => $comment->created_at?->toIso8601String(),
            ],
        ];
    }

    public static function page(Page $page, bool $firstPublish): array
    {
        /** @var Page $copy */
        $copy = self::copy($page, ['project.workspace']);
        $project = $copy->project;

        return [
            'page' => [
                'id' => $copy->id,
                'uuid' => $copy->uuid,
                'title' => $copy->name,
                'version' => (int) $copy->current_version,
                'first_publish' => $firstPublish,
                'url' => $project?->workspace ? route('pages.show', [$project->workspace->slug, $project->slug, $copy->uuid]) : null,
                'published_at' => $copy->published_at?->toIso8601String(),
                'project' => self::project($project),
            ],
        ];
    }

    public static function sprint(Sprint $sprint, Board $board, array $extra = []): array
    {
        $project = Project::withTrashed()->with('workspace')->find($board->project_id);

        return [
            'sprint' => [
                'id' => $sprint->id,
                'name' => $sprint->name,
                'goal' => $sprint->goal,
                'status' => $sprint->status,
                'start_date' => $sprint->start_date?->toDateString(),
                'end_date' => $sprint->end_date?->toDateString(),
                'committed_issues' => $sprint->committed_issues,
                'committed_points' => $sprint->committed_points,
                'completed_issues' => $sprint->completed_issues,
                'completed_points' => $sprint->completed_points,
                'url' => $project?->workspace ? route('boards.backlog', [$project->workspace->slug, $project->slug, $board->slug]) : null,
                'board' => ['id' => $board->id, 'name' => $board->name, 'slug' => $board->slug],
                'project' => self::project($project),
            ] + $extra,
        ];
    }

    public static function ticketWorkspaceId(Ticket $ticket): ?int
    {
        $id = DB::table('helpdesks')->join('projects', 'projects.id', '=', 'helpdesks.project_id')
            ->where('helpdesks.id', $ticket->helpdesk_id)->value('projects.workspace_id');

        return $id ? (int) $id : null;
    }

    public static function ticket(Ticket $ticket): array
    {
        /** @var Ticket $copy */
        $copy = self::copy($ticket, ['helpdesk.project.workspace', 'column:id,name,is_done_column', 'contact:id,name,email', 'assignee:id,name', 'priority:id,name']);
        $helpdesk = $copy->helpdesk;
        $project = $helpdesk?->project;

        return [
            'id' => $copy->id,
            'key' => $copy->key,
            'subject' => $copy->subject,
            'excerpt' => self::excerpt($copy->description),
            'status' => $copy->column?->name,
            'resolved' => (bool) $copy->column?->is_done_column,
            'priority' => $copy->priority?->name,
            'assignee' => self::person($copy->assignee),
            // the contact as agents see it on the ticket
            'contact' => $copy->contact ? ['name' => $copy->contact->name, 'email' => $copy->contact->email] : null,
            'url' => $project?->workspace ? route('tickets.show', [$project->workspace->slug, $project->slug, $helpdesk->slug, $copy->id]) : null,
            'helpdesk' => $helpdesk ? ['id' => $helpdesk->id, 'name' => $helpdesk->name, 'slug' => $helpdesk->slug] : null,
            'project' => self::project($project),
            'created_at' => $copy->created_at?->toIso8601String(),
            'completed_at' => $copy->completed_at?->toIso8601String(),
        ];
    }

    public static function ticketReply(Ticket $ticket, TicketReply $reply): array
    {
        /** @var TicketReply $copy */
        $copy = self::copy($reply, ['user:id,name', 'contact:id,name']);

        return [
            'ticket' => self::ticket($ticket),
            'reply' => [
                'id' => $copy->id,
                'excerpt' => self::excerpt($copy->body),
                'internal' => (bool) $copy->is_internal_note,
                'from' => $copy->ticket_contact_id ? 'contact' : 'agent',
                'author' => $copy->user ? self::person($copy->user) : ($copy->contact ? ['name' => $copy->contact->name] : null),
                'created_at' => $copy->created_at?->toIso8601String(),
            ],
        ];
    }

    public static function timeEntry(TimeEntry $entry): array
    {
        /** @var TimeEntry $copy */
        $copy = self::copy($entry, ['project.workspace', 'user:id,name', 'activity:id,name', 'item.board']);
        $item = $copy->item;

        return [
            'time_entry' => [
                'id' => $copy->id,
                'minutes' => $copy->minutes,
                'duration' => Item::formatDuration((int) $copy->minutes),
                'spent_on' => $copy->spent_on?->toDateString(),
                'note' => $copy->note,
                'billable' => (bool) $copy->billable,
                'source' => $copy->source,
                'activity' => $copy->activity?->name,
                'user' => self::person($copy->user),
                'item' => $item ? ['id' => $item->id, 'key' => $item->key, 'title' => $item->title, 'url' => self::itemUrl($item)] : null,
                'project' => self::project($copy->project),
                'created_at' => $copy->created_at?->toIso8601String(),
            ],
        ];
    }

    public static function billingReport(BillingReport $report): array
    {
        /** @var BillingReport $copy */
        $copy = self::copy($report, ['workspace', 'client:id,name']);

        return [
            'report' => [
                'id' => $copy->id,
                'kind' => $copy->kind,
                'number' => $copy->number,
                'title' => $copy->title,
                'status' => $copy->status,
                'client' => $copy->client ? ['id' => $copy->client->id, 'name' => $copy->client->name] : null,
                'period_from' => $copy->period_from?->toDateString(),
                'period_to' => $copy->period_to?->toDateString(),
                'currency' => $copy->currency,
                'total_minutes' => $copy->total_minutes,
                'subtotal_cents' => $copy->subtotal_cents,
                'tax_cents' => $copy->tax_cents,
                'total_cents' => $copy->total_cents,
                'finalized_at' => $copy->finalized_at?->toIso8601String(),
                'paid_at' => $copy->paid_at?->toIso8601String(),
                'url' => $copy->workspace ? route('billing.reports.show', [$copy->workspace->slug, $copy->id]) : null,
            ],
        ];
    }

    private static function itemUrl(Item $item): ?string
    {
        $board = $item->relationLoaded('board') ? $item->board : Board::withTrashed()->with('project.workspace')->find($item->board_id);
        $project = $board?->project;

        return $project?->workspace ? route('boards.items.show', [$project->workspace->slug, $project->slug, $board->slug, $item->id]) : null;
    }

    private static function excerpt(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', $html)), ENT_QUOTES | ENT_HTML5)) ?? '');

        return $text === '' ? null : Str::limit($text, self::EXCERPT);
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    private static function copy(Model $model, array $relations): Model
    {
        $copy = $model->newFromBuilder($model->getAttributes());
        $copy->load($relations);

        return $copy;
    }
}
