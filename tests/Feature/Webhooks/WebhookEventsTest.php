<?php

namespace Tests\Feature\Webhooks;

use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\LeadForm;
use App\Models\Page;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Boards\ItemComments;
use App\Support\Boards\SprintLifecycle;
use App\Support\Events\DomainEvents;
use App\Support\LeadForms\LeadIntake;
use App\Support\Search\SearchIndexer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Every DomainEvents name is dispatched from its hook point with the documented payload
 * (BRAI-104). Deliveries run inline (sync queue, Http::fake), so the stored envelopes are checked.
 */
class WebhookEventsTest extends TestCase
{
    use InteractsWithBoards;
    use InteractsWithWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Http::fake(['*' => Http::response('ok')]);
        Notification::fake();
        $this->hook(['*']);
    }

    private function only(string $event): array
    {
        $envelopes = $this->envelopes($event);
        $this->assertCount(1, $envelopes, "expected exactly one {$event}");

        return $envelopes[0];
    }

    public function test_item_lifecycle_events(): void
    {
        $this->actingAs($this->editor);
        $item = $this->item(['title' => 'Webhook issue', 'column_id' => $this->column('to-do')]);

        $created = $this->only('item.created');
        $this->assertSame('item.created', $created['event']);
        $this->assertSame(['id' => $this->editor->id, 'name' => $this->editor->name], $created['actor']);
        $data = $created['data']['item'];
        $this->assertSame([$item->id, $item->key, 'Webhook issue', 'To Do', false], [$data['id'], $data['key'], $data['title'], $data['status'], $data['completed']]);
        $this->assertSame(route('boards.items.show', [$this->workspace->slug, $this->project->slug, $this->board->slug, $item->id]), $data['url']);
        $this->assertSame(['id' => $this->project->id, 'key' => 'BRAI', 'name' => $this->project->name, 'slug' => $this->project->slug], $data['project']);

        // assignment
        $item->update(['assigned_to' => $this->viewer->id]);
        $assigned = $this->only('item.assigned')['data'];
        $this->assertSame(['id' => $this->viewer->id, 'name' => $this->viewer->name], $assigned['item']['assignee']);
        $this->assertNull($assigned['previous_assignee']);
        $this->assertStringNotContainsString($this->viewer->email, json_encode($assigned)); // no member emails

        // plain field edits → item.updated with the changed fields
        $item->update(['title' => 'Renamed', 'story_points' => 3]);
        $this->assertSame(['title', 'story_points'], $this->only('item.updated')['data']['changed']);

        // move to Done → item.moved + item.completed
        $item->fresh()->moveToColumn($this->board->columns()->where('is_done_column', true)->firstOrFail());
        $moved = $this->only('item.moved')['data'];
        $this->assertSame(['To Do', 'Done'], [$moved['from']['column'], $moved['to']['column']]);
        $completed = $this->only('item.completed')['data']['item'];
        $this->assertTrue($completed['completed']);
        $this->assertNotNull($completed['completed_at']);

        // comments
        ItemComments::add($item->fresh(), $this->editor, '<p>Looks <strong>good</strong> &amp; done</p>');
        $comment = $this->only('item.commented')['data'];
        $this->assertSame('Looks good & done', $comment['comment']['excerpt']);
        $this->assertSame($item->id, $comment['item']['id']);

        $item->fresh()->delete();
        $deleted = $this->only('item.deleted')['data'];
        $this->assertSame([$item->id, false], [$deleted['item']['id'], $deleted['permanent']]);
    }

    public function test_bulk_position_saves_raise_nothing(): void
    {
        $item = $this->item();
        WebhookDelivery::query()->delete();

        $item->update(['position' => 7]);

        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_page_published(): void
    {
        $page = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'name' => 'Release notes', 'status' => 'draft']);

        $page->publish($this->editor);
        $first = $this->only('page.published')['data']['page'];
        $this->assertSame(['Release notes', true, $page->uuid], [$first['title'], $first['first_publish'], $first['uuid']]);
        $this->assertSame(route('pages.show', [$this->workspace->slug, $this->project->slug, $page->uuid]), $first['url']);
        $this->assertArrayNotHasKey('content', $first);

        $page->fresh()->publishChanges($this->editor);
        $this->assertFalse($this->envelopes('page.published')[1]['data']['page']['first_publish']);
    }

    public function test_sprint_started_and_completed(): void
    {
        $sprint = Sprint::factory()->create(['board_id' => $this->board->id, 'name' => 'Sprint 7']);
        $this->item(['sprint_id' => $sprint->id, 'story_points' => 5]);

        SprintLifecycle::start($this->board, $sprint, '2026-10-01', '2026-10-14', 'Ship webhooks');
        $started = $this->only('sprint.started')['data']['sprint'];
        $this->assertSame(['Sprint 7', 'Ship webhooks', 'active', '2026-10-01', 1, 5], [$started['name'], $started['goal'], $started['status'], $started['start_date'], $started['committed_issues'], $started['committed_points']]);
        $this->assertSame(route('boards.backlog', [$this->workspace->slug, $this->project->slug, $this->board->slug]), $started['url']);

        SprintLifecycle::complete($this->board, $sprint->fresh(), null);
        $completed = $this->only('sprint.completed')['data']['sprint'];
        $this->assertSame(['completed', 1, 'backlog'], [$completed['status'], $completed['open_issues_moved'], $completed['open_issues_moved_to']]);
    }

    public function test_helpdesk_ticket_events(): void
    {
        $admin = $this->admin();
        $this->project->users()->attach($admin->id, ['role' => 'admin']);
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $admin->id]);
        $params = [$this->workspace->slug, $this->project->slug, $helpdesk->slug];

        // agent-created
        $this->actingAs($admin)->post(route('tickets.store', $params), [
            'subject' => 'Printer on fire', 'description' => '<p>Since 9:00</p>', 'column_id' => $helpdesk->columns()->first()->id,
            'contact_email' => 'cleo@example.com', 'contact_name' => 'Cleo', 'notify_contact' => false,
        ])->assertSessionHasNoErrors();
        $ticket = Ticket::where('subject', 'Printer on fire')->firstOrFail();
        $created = $this->only('ticket.created')['data'];
        $this->assertSame(['agent', $ticket->key, 'Since 9:00', ['name' => 'Cleo', 'email' => 'cleo@example.com']], [$created['source'], $created['ticket']['key'], $created['ticket']['excerpt'], $created['ticket']['contact']]);
        $this->assertSame(route('tickets.show', [...$params, $ticket->id]), $created['ticket']['url']);

        // portal-created (no actor)
        auth()->logout();
        $this->postJson(route('helpdesks.tickets.store-public', $helpdesk->uuid), ['subject' => 'Portal question', 'description' => 'Hi', 'contact_email' => 'p@example.com'])->assertOk();
        $portal = $this->envelopes('ticket.created')[1];
        $this->assertSame(['portal', null], [$portal['data']['source'], $portal['actor']]);

        // agent reply + internal note, contact reply
        $this->actingAs($admin)->post(route('tickets.replies.store', [...$params, $ticket->id]), ['body' => '<p>On it</p>', 'is_internal_note' => true])->assertSessionHasNoErrors();
        $reply = $this->only('ticket.replied')['data']['reply'];
        $this->assertSame(['On it', true, 'agent', ['id' => $admin->id, 'name' => $admin->name]], [$reply['excerpt'], $reply['internal'], $reply['from'], $reply['author']]);

        auth()->logout();
        $this->postJson(route('helpdesks.tickets.replies.store-public', [$helpdesk->uuid, $ticket->uuid]), ['body' => 'Thanks', 'access_token' => $ticket->publicAccessToken()])->assertOk();
        $this->assertSame(['contact', ['name' => 'Cleo']], array_values(array_intersect_key($this->envelopes('ticket.replied')[1]['data']['reply'], array_flip(['from', 'author']))));

        // resolved
        $done = $helpdesk->columns()->where('is_done_column', true)->firstOrFail();
        $this->actingAs($admin)->patch(route('tickets.move', [...$params, $ticket->id]), ['column_id' => $done->id, 'position' => 0, 'resolution_message' => 'Replaced the toner'])->assertSessionHasNoErrors();
        $resolved = $this->only('ticket.resolved')['data'];
        $this->assertSame([true, 'Resolved', 'Replaced the toner'], [$resolved['ticket']['resolved'], $resolved['ticket']['status'], $resolved['resolution']]);
    }

    public function test_lead_created(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('lead-forms.store', [$this->workspace->slug, $this->project->slug]), ['name' => 'Demo requests', 'template' => 'demo_request']);
        $form = LeadForm::where('name', 'Demo requests')->firstOrFail();
        auth()->logout();

        $lead = LeadIntake::create($form, ['f_name' => 'Jane Doe', 'f_email' => 'jane@example.com', 'f_company' => 'Acme'], 'form');

        $envelope = $this->only('lead.created');
        $this->assertNull($envelope['actor']);
        $this->assertSame(['form', 'lead'], array_keys($envelope['data']));
        $this->assertSame([$lead->uuid, 'Jane Doe', 'jane@example.com', 'Acme'], [$envelope['data']['lead']['id'], $envelope['data']['lead']['name'], $envelope['data']['lead']['email'], $envelope['data']['lead']['company']]);
    }

    public function test_time_entry_created_carries_no_rates(): void
    {
        $item = $this->item(['title' => 'Billable work']);
        $this->actingAs($this->editor);
        $entry = $item->logWork(90, 'Pairing', null, '2026-10-20');

        $data = $this->only('time_entry.created')['data']['time_entry'];
        $this->assertSame([$entry->id, 90, '1h 30m', '2026-10-20', 'Pairing'], [$data['id'], $data['minutes'], $data['duration'], $data['spent_on'], $data['note']]);
        $this->assertSame(['id' => $this->editor->id, 'name' => $this->editor->name], $data['user']);
        $this->assertSame($item->key, $data['item']['key']);
        $this->assertStringNotContainsString('rate', json_encode($data));
    }

    public function test_a_failing_payload_query_does_not_abort_the_callers_transaction(): void
    {
        DB::transaction(function () {
            try {
                DomainEvents::emit($this->workspace, 'item.created', fn () => DB::select('SELECT * FROM no_such_table'));
                $this->fail('unit tests surface payload errors');
            } catch (QueryException) {
                // in production this is reported, not thrown
            }

            // the savepoint was rolled back, the caller's transaction is still usable (Postgres)
            $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS one')->one);
        });
    }

    public function test_every_event_has_a_label_and_area(): void
    {
        $grouped = array_merge(...array_values(DomainEvents::grouped()));
        $this->assertSame(array_keys(DomainEvents::EVENTS), array_keys($grouped));
        $this->assertArrayNotHasKey('Other', DomainEvents::grouped());
    }

    public function test_without_a_listening_webhook_no_payload_is_built(): void
    {
        Webhook::query()->update(['events' => ['lead.created']]);
        $item = $this->item();

        // the search index writes after the request (BRAI-55), as in production — not counted here
        app(SearchIndexer::class)->defer();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $item->update(['title' => 'Quiet change']);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        app(SearchIndexer::class)->release();

        $this->assertSame(0, WebhookDelivery::count());
        // one lookup of the workspace + one "is anybody listening" check; no payload queries
        $this->assertSame(1, $queries->filter(fn (string $q) => str_contains($q, '"webhooks"'))->count());
        $this->assertSame(0, $queries->filter(fn (string $q) => str_contains($q, '"item_types"') || str_contains($q, '"board_columns"'))->count());
    }
}
