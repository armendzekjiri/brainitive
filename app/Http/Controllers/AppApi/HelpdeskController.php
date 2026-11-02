<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\CreatesOnce;
use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListTicketsRequest;
use App\Http\Requests\AppApi\StoreTicketReplyRequest;
use App\Http\Requests\AppApi\UpdateTicketRequest;
use App\Http\Resources\AppApi\HelpdeskDetailResource;
use App\Http\Resources\AppApi\HelpdeskResource;
use App\Http\Resources\AppApi\TicketCollection;
use App\Http\Resources\AppApi\TicketDetailResource;
use App\Http\Resources\AppApi\TicketReplyResource;
use App\Models\Helpdesk;
use App\Models\HelpdeskColumn;
use App\Models\ItemPriority;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Support\Boards\IssueMarkdown;
use App\Support\Helpdesks\Sla\SlaStatus;
use App\Support\Helpdesks\TicketActions;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — the agent side of helpdesks (BRAI-420): a project's helpdesks, the ticket inbox, a ticket with
 * its thread, replies / internal notes, and status / assignee / priority changes — the web's TicketController
 * through the shared TicketActions and TicketPolicy. Every project member reads tickets (as the web); editors
 * and admins change them. The customer side (widget, portal, public replies) is public and never in the App
 * API; SLA rules, templates, widget, portal and mailbox settings: web only.
 */
#[Group('Helpdesk', weight: 290)]
class HelpdeskController extends Controller
{
    use CreatesOnce;
    use ResolvesVisible;

    private const TICKET_WITH = ['helpdesk.project.workspace', 'column', 'priority', 'assignee', 'contact'];

    /**
     * The helpdesks of a project, in the web's order, with their statuses and open ticket counts.
     *
     * Not paginated (a project has a handful).
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function index(Request $request, string $project): AnonymousResourceCollection
    {
        $project = $this->visibleProject($request, $project);

        $helpdesks = Helpdesk::query()->where('project_id', $project->id)
            ->with(['columns' => fn ($q) => $q->orderBy('position')->orderBy('id')])
            ->withCount(['tickets as open_tickets_count' => fn ($q) => $this->open($q)])
            ->orderBy('position')->orderBy('id')
            ->get()
            ->each(fn (Helpdesk $h) => $h->setRelation('project', $project));

        return HelpdeskResource::collection($helpdesks);
    }

    /**
     * One helpdesk: statuses, the project's priorities (for `PATCH /tickets/{id}`), open count.
     *
     * Assignees: `GET /workspaces/{slug}/members`.
     *
     * @throws NotFoundHttpException the helpdesk doesn't exist or the user can't open its project (404)
     */
    public function show(Request $request, string $helpdesk): HelpdeskDetailResource
    {
        $helpdesk = $this->visibleHelpdesk($request, $helpdesk);
        $helpdesk->load(['columns' => fn ($q) => $q->orderBy('position')->orderBy('id')])
            ->loadCount(['tickets as open_tickets_count' => fn ($q) => $this->open($q)])
            ->setRelation('priorities', ItemPriority::forProject($helpdesk->project)->orderBy('order')->get());

        return HelpdeskDetailResource::make($helpdesk);
    }

    /**
     * The tickets of a helpdesk, newest first.
     *
     * `status=open` (default: not in a done column) | `done` | `all`; `column`, `assignee=me|unassigned|<id>`,
     * `priority`, `sla=breached|at_risk|on_track|paused|met|none` (the web's SLA filter). Cursor-paginated,
     * `updated_since`.
     *
     * @throws NotFoundHttpException the helpdesk doesn't exist or the user can't open its project (404)
     */
    public function tickets(ListTicketsRequest $request, string $helpdesk): TicketCollection
    {
        $helpdesk = $this->visibleHelpdesk($request, $helpdesk);
        $v = $request->validated();
        $status = $v['status'] ?? 'open';
        $assignee = $v['assignee'] ?? null;
        $since = $request->updatedSince();

        $query = Ticket::query()->where('helpdesk_id', $helpdesk->id)
            ->with(['column', 'priority', 'assignee', 'contact'])
            ->when($status === 'open', fn ($q) => $this->open($q))
            ->when($status === 'done', fn ($q) => $q->whereHas('column', fn ($c) => $c->where('is_done_column', true)))
            ->when(isset($v['column']), fn ($q) => $q->where('column_id', (int) $v['column']))
            ->when(isset($v['priority']), fn ($q) => $q->where('priority_id', (int) $v['priority']))
            ->when($assignee === 'me', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($assignee === 'unassigned', fn ($q) => $q->whereNull('assigned_to'))
            ->when($assignee !== null && ctype_digit($assignee), fn ($q) => $q->where('assigned_to', (int) $assignee))
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since));
        if (isset($v['sla'])) {
            SlaStatus::filter($query, $v['sla'], now());
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Ticket $t) => $t->setRelation('helpdesk', $helpdesk));

        return new TicketCollection($page);
    }

    /**
     * One ticket with its whole thread (oldest first), custom fields, files and `can.update`.
     *
     * Internal notes are in the thread with `internal: true` (agents only, as the web).
     *
     * @throws NotFoundHttpException the ticket doesn't exist or the user can't open its project (404)
     */
    public function ticket(Request $request, string $ticket): TicketDetailResource
    {
        return TicketDetailResource::make($this->detail($this->visibleTicket($request, $ticket)));
    }

    /**
     * Change a ticket: status (column), assignee, priority, subject.
     *
     * Only the sent fields change. Moving into a done column resolves it: `resolution_message` is saved as a
     * reply and `notify_contact` emails the customer; watchers hear about the status change, a new assignee is
     * notified. Send the `updated_at` you read to get 409 instead of overwriting a newer change. Project viewers
     * → 403.
     *
     * @throws NotFoundHttpException the ticket doesn't exist or the user can't open its project (404)
     * @throws ConflictHttpException the ticket changed since `updated_at` (409)
     */
    public function update(UpdateTicketRequest $request, string $ticket): TicketDetailResource
    {
        $row = $request->ticket();
        $v = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($row, $v, $user) {
            $locked = Ticket::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if (isset($v['updated_at']) && $locked->updated_at !== null && $locked->updated_at->gt(Carbon::parse($v['updated_at']))) {
                abort(409, 'The ticket changed since you loaded it. Reload it and try again.');
            }

            $changes = [];
            foreach (['subject' => 'subject', 'priority_id' => 'priority_id', 'assignee_id' => 'assigned_to'] as $in => $column) {
                if (array_key_exists($in, $v)) {
                    $changes[$column] = $v[$in];
                }
            }
            if ($changes !== []) {
                TicketActions::update($row, $user, $changes);
            }
            if (array_key_exists('column_id', $v)) {
                $row->load('column');
                TicketActions::move($row, HelpdeskColumn::findOrFail($v['column_id']), (int) ($v['position'] ?? 0), $user, $v['resolution_message'] ?? null, (bool) ($v['notify_contact'] ?? false));
            }
        });

        return TicketDetailResource::make($this->detail($row->fresh()));
    }

    /**
     * Reply to the customer, or add an internal note.
     *
     * The reply is emailed to the customer (through the helpdesk's mailbox when one is connected); an internal
     * note never is. Watchers are notified. 201, or 200 on a replayed `request_id`. Project viewers → 403.
     *
     * @throws NotFoundHttpException the ticket doesn't exist or the user can't open its project (404)
     */
    public function reply(StoreTicketReplyRequest $request, string $ticket): JsonResponse
    {
        $row = $request->ticket();
        $v = $request->validated();
        $html = (string) IssueMarkdown::toHtml($v['body'], $row->helpdesk->project);
        abort_if(trim(strip_tags($html)) === '' && ! str_contains($html, '<img'), 422, 'The reply is empty.');

        [$reply, $replayed] = $this->createOnce($request, 'tickets.replies.store', TicketReply::class,
            ['ticket' => $row->id, 'body' => $v['body'], 'internal' => (bool) ($v['internal'] ?? false)],
            fn () => TicketActions::reply($row, $request->user(), $html, (bool) ($v['internal'] ?? false)),
            fn (TicketReply $reply) => $reply->ticket !== null && $request->user()->can('view', $reply->ticket));
        $reply->load(['user', 'contact', 'attachments']);

        if ($replayed) {
            return (new TicketReplyResource($reply))->response();
        }

        return (new TicketReplyResource($reply))->response()->setStatusCode(201);
    }

    /** @param Builder<Ticket> $query */
    private function open(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('column_id')->orWhereHas('column', fn ($c) => $c->where('is_done_column', false)));
    }

    private function visibleHelpdesk(Request $request, string $id): Helpdesk
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1, 404);
        $helpdesk = Helpdesk::with('project.workspace')->find((int) $id);
        abort_unless($helpdesk instanceof Helpdesk && $helpdesk->project?->workspace !== null && $request->user()->can('view', $helpdesk), 404);

        return $helpdesk;
    }

    private function visibleTicket(Request $request, string $id): Ticket
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1, 404);
        $ticket = Ticket::with(self::TICKET_WITH)->find((int) $id);
        abort_unless($ticket instanceof Ticket && $ticket->helpdesk?->project?->workspace !== null && $request->user()->can('view', $ticket), 404);

        return $ticket;
    }

    private function detail(Ticket $ticket): Ticket
    {
        return $ticket->load([
            ...self::TICKET_WITH,
            'helpdesk.fields' => fn ($q) => $q->orderBy('position'),
            'fieldValues',
            'attachments' => fn ($q) => $q->orderBy('created_at'),
            'replies' => fn ($q) => $q->with(['user:id,name', 'contact:id,name,email', 'attachments'])->orderBy('created_at')->orderBy('id')->limit(500),
            'watchers:users.id',
        ]);
    }
}
