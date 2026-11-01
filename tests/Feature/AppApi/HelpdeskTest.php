<?php

namespace Tests\Feature\AppApi;

use App\Models\Helpdesk;
use App\Models\ItemPriority;
use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\TicketReply;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketReplyNotification;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 helpdesk, agent side (BRAI-420): helpdesks of a project, the ticket inbox with the web's filters
 * (status, assignee, SLA), a ticket's thread, replies / internal notes and status / assignee / priority
 * changes through TicketActions (shared with the web). Project viewers read, editors change; other
 * workspaces' tickets are 404. The customer side (widget, portal) is never part of the App API.
 */
class HelpdeskTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private Helpdesk $helpdesk;

    private TicketContact $contact;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->editor->id]);
        $this->contact = TicketContact::create(['email' => 'cleo@customer.test', 'name' => 'Cleo Customer', 'uuid' => (string) Str::uuid()]);
        $this->token = $this->appToken($this->editor);
    }

    private function column(string $slug): int
    {
        return $this->helpdesk->columns()->where('slug', $slug)->value('id');
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::unguarded(fn () => $this->helpdesk->tickets()->create($attributes + [
            'subject' => 'Printer on fire',
            'description' => '<p>It <strong>smokes</strong>.</p>',
            'column_id' => $this->column('open'),
            'ticket_contact_id' => $this->contact->id,
            'key' => 'SUP-'.Str::random(4),
            'position' => 0,
            'metadata' => ['ip' => '203.0.113.9', 'user_agent' => 'Secret/1.0'],
        ]))->refresh();
    }

    public function test_helpdesks_of_a_project_with_statuses_and_counts(): void
    {
        $this->ticket();
        $this->ticket(['column_id' => $this->column('resolved')]);

        $this->getJson("/api/v1/projects/{$this->project->id}/helpdesks", $this->appHeaders($this->token))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Support')
            ->assertJsonPath('data.0.open_count', 1)
            ->assertJsonPath('data.0.columns.2.is_done', true)
            ->assertJsonPath('data.0.can.manage_tickets', true);

        $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}", $this->appHeaders($this->token))->assertOk()
            ->assertJsonPath('data.slug', 'support')
            ->assertJsonPath('data.priorities.0.id', ItemPriority::forProject($this->project)->orderBy('order')->value('id'))
            ->assertJsonMissingPath('data.forwarding_local_part');

        $viewer = $this->appToken($this->viewer);
        $this->getJson("/api/v1/projects/{$this->project->id}/helpdesks", $this->appHeaders($viewer))->assertOk()->assertJsonPath('data.0.can.manage_tickets', false);
        $outsider = $this->appToken($this->outsider);
        $this->getJson("/api/v1/projects/{$this->project->id}/helpdesks", $this->appHeaders($outsider))->assertNotFound();
        $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}", $this->appHeaders($outsider))->assertNotFound();
    }

    public function test_the_inbox_filters_and_paginates(): void
    {
        $old = $this->ticket(['subject' => 'Old', 'created_at' => now()->subDays(2)]);
        $mine = $this->ticket(['subject' => 'Mine', 'assigned_to' => $this->editor->id, 'created_at' => now()->subDay()]);
        $done = $this->ticket(['subject' => 'Done', 'column_id' => $this->column('resolved')]);
        $late = $this->ticket(['subject' => 'Late']);
        Ticket::whereKey($late->id)->update(['sla_first_response_due_at' => now()->subHour(), 'sla_resolution_due_at' => now()->addDay()]);
        $risky = $this->ticket(['subject' => 'Risky']);
        Ticket::whereKey($risky->id)->update(['sla_first_response_due_at' => now()->addMinutes(10), 'sla_first_response_warn_at' => now()->subMinutes(5)]);

        $headers = $this->appHeaders($this->token);
        $first = $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}/tickets?limit=2", $headers)->assertOk()->assertJsonCount(2, 'data');
        $second = $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}/tickets?limit=2&cursor=".urlencode($first->json('next_cursor')), $headers)->assertOk();
        $this->assertSame(['Mine', 'Old'], array_column($second->json('data'), 'subject'));
        $this->assertNull($second->json('next_cursor'));

        $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}/tickets?status=done", $headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $done->id)->assertJsonPath('data.0.status.is_done', true);
        $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}/tickets?assignee=me", $headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id)->assertJsonPath('data.0.assignee.id', $this->editor->id);
        $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}/tickets?assignee=unassigned", $headers)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}/tickets?sla=breached", $headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $late->id)
            ->assertJsonPath('data.0.sla.state', 'breached')->assertJsonPath('data.0.sla.breached_targets', ['first_response']);
        $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}/tickets?sla=at_risk", $headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $risky->id)->assertJsonPath('data.0.sla.state', 'respond')->assertJsonPath('data.0.sla.at_risk', true);
        $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}/tickets?sla=none", $headers)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}/tickets?sla=late", $headers)->assertUnprocessable()->assertJsonValidationErrors('sla');

        // the customer as the agent view shows them; no request metadata
        $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}/tickets?limit=1", $headers)
            ->assertJsonPath('data.0.contact', ['name' => 'Cleo Customer', 'email' => 'cleo@customer.test'])
            ->assertJsonMissingPath('data.0.metadata');
    }

    public function test_a_ticket_with_its_thread(): void
    {
        $ticket = $this->ticket();
        TicketReply::create(['ticket_id' => $ticket->id, 'ticket_contact_id' => $this->contact->id, 'body' => '<p>Any news?</p>']);
        TicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $this->editor->id, 'body' => '<p>Checking with the vendor</p>', 'is_internal_note' => true]);

        $this->getJson("/api/v1/tickets/{$ticket->id}", $this->appHeaders($this->appToken($this->viewer)))->assertOk()
            ->assertJsonPath('data.description_markdown', 'It **smokes**.')
            ->assertJsonPath('data.replies.0.from', 'contact')
            ->assertJsonPath('data.replies.0.author.name', 'Cleo Customer')
            ->assertJsonPath('data.replies.1.internal', true)
            ->assertJsonPath('data.replies.1.author.id', $this->editor->id)
            ->assertJsonPath('data.can.update', false)
            ->assertJsonMissingPath('data.metadata');
        $this->getJson("/api/v1/tickets/{$ticket->id}", $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
        $this->getJson('/api/v1/tickets/abc', $this->appHeaders($this->token))->assertNotFound();
    }

    public function test_reply_and_internal_note(): void
    {
        $ticket = $this->ticket();
        $ticket->watchers()->attach($this->viewer->id);
        $body = ['body' => "Thanks, **fixed** it.\n\n- [Guide](https://example.test/guide)", 'request_id' => (string) Str::uuid()];

        $reply = $this->postJson("/api/v1/tickets/{$ticket->id}/replies", $body, $this->appHeaders($this->token))->assertCreated()
            ->assertJsonPath('data.from', 'agent')->assertJsonPath('data.internal', false);
        $this->assertStringContainsString('<strong>fixed</strong>', $reply->json('data.body_html'));
        $this->assertStringContainsString('[Guide](https://example.test/guide)', $reply->json('data.body_markdown'));
        $this->postJson("/api/v1/tickets/{$ticket->id}/replies", $body, $this->appHeaders($this->token))->assertOk()->assertJsonPath('data.id', $reply->json('data.id'));
        $this->assertSame(1, TicketReply::count());
        Notification::assertSentTo($this->contact, TicketReplyNotification::class);
        Notification::assertSentTo($this->viewer, TicketReplyNotification::class);

        $this->postJson("/api/v1/tickets/{$ticket->id}/replies", ['body' => 'Vendor says Monday', 'internal' => true], $this->appHeaders($this->token))
            ->assertCreated()->assertJsonPath('data.internal', true);
        Notification::assertSentToTimes($this->contact, TicketReplyNotification::class, 1);

        $this->postJson("/api/v1/tickets/{$ticket->id}/replies", ['body' => 'x'], $this->appHeaders($this->appToken($this->viewer)))->assertForbidden();
        $this->postJson("/api/v1/tickets/{$ticket->id}/replies", [], $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
        $this->postJson("/api/v1/tickets/{$ticket->id}/replies", [], $this->appHeaders($this->token))->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_status_assignee_priority_and_resolve(): void
    {
        $ticket = $this->ticket();
        $priority = ItemPriority::forProject($this->project)->orderBy('order')->firstOrFail();
        $headers = $this->appHeaders($this->token);

        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['assignee_id' => $this->viewer->id, 'priority_id' => $priority->id, 'subject' => 'Printer fixed?'], $headers)->assertOk()
            ->assertJsonPath('data.assignee.id', $this->viewer->id)
            ->assertJsonPath('data.priority.id', $priority->id)
            ->assertJsonPath('data.subject', 'Printer fixed?');
        Notification::assertSentTo($this->viewer, TicketAssignedNotification::class);

        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['column_id' => $this->column('resolved'), 'resolution_message' => 'Replaced the fuser.', 'notify_contact' => true], $headers)->assertOk()
            ->assertJsonPath('data.status.is_done', true)
            ->assertJsonPath('data.replies.0.body_markdown', 'Replaced the fuser.');
        $this->assertNotNull($ticket->fresh()->completed_at);
        Notification::assertSentTo($this->contact, TicketResolvedNotification::class);

        // stale write, foreign column / assignee, viewer, outsider
        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'Late', 'updated_at' => now()->subDay()->toIso8601String()], $headers)->assertStatus(409)->assertJsonPath('code', 'conflict');
        $other = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Other', 'slug' => 'other', 'created_by' => $this->editor->id]);
        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['column_id' => $other->columns()->value('id')], $headers)->assertUnprocessable()->assertJsonValidationErrors('column_id');
        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['assignee_id' => $this->outsider->id], $headers)->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'x'], $this->appHeaders($this->appToken($this->viewer)))->assertForbidden();
        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'x'], $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
        $this->assertSame('Printer fixed?', $ticket->fresh()->subject);
    }

    public function test_tokens_of_other_clients_and_no_token_are_401(): void
    {
        $ticket = $this->ticket();
        $this->getJson("/api/v1/tickets/{$ticket->id}")->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson("/api/v1/helpdesks/{$this->helpdesk->id}/tickets", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
    }
}
