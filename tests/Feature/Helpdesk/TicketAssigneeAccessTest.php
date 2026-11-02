<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Helpdesk;
use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketReplyNotification;
use App\Support\Helpdesks\TicketActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Codex web review I: a ticket is only assigned to (and only notifies) people who can view it — a workspace member
 * outside the helpdesk's project must not get its subject and key. The same for watchers who lost access.
 */
class TicketAssigneeAccessTest extends TestCase
{
    use ActsAsAppClient;
    use InteractsWithBoards;
    use RefreshDatabase;

    protected Helpdesk $helpdesk;

    protected User $admin;

    protected User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        $this->admin = User::find($this->workspace->owner_id);
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->admin->id]);
        // in the workspace, not in the project
        $this->stranger = User::factory()->create();
        $this->workspace->addUser($this->stranger, 'member');
        $this->assertFalse($this->stranger->hasProjectAccess($this->project));
    }

    private function ticket(): Ticket
    {
        $contact = TicketContact::create(['email' => 'c@example.com', 'uuid' => (string) str()->uuid()]);

        return $this->helpdesk->tickets()->create([
            'subject' => 'Salary data', 'column_id' => $this->helpdesk->columns()->first()->id, 'ticket_contact_id' => $contact->id,
            'key' => $this->project->getNextTicketKey(), 'position' => 1,
        ]);
    }

    public function test_web_create_and_update_refuse_an_assignee_without_access(): void
    {
        $this->actingAs($this->editor)->post(route('tickets.store', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug]), [
            'subject' => 'Salary data', 'column_id' => $this->helpdesk->columns()->first()->id, 'contact_email' => 'c@example.com',
            'assigned_to' => $this->stranger->id,
        ])->assertSessionHasErrors('assigned_to');
        $this->assertSame(0, Ticket::count());

        $ticket = $this->ticket();
        // a project member still can be assigned
        $this->actingAs($this->editor)->patch(route('tickets.update', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug, $ticket->id]), [
            'assigned_to' => $this->viewer->id,
        ])->assertSessionHasNoErrors();
        Notification::assertSentTo($this->viewer, TicketAssignedNotification::class);

        $this->actingAs($this->editor)->patch(route('tickets.update', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug, $ticket->id]), [
            'assigned_to' => $this->stranger->id,
        ])->assertSessionHasErrors('assigned_to');
        $this->assertSame($this->viewer->id, $ticket->fresh()->assigned_to);
        Notification::assertNotSentTo($this->stranger, TicketAssignedNotification::class);
    }

    public function test_the_app_api_refuses_an_assignee_without_access(): void
    {
        $ticket = $this->ticket();
        $this->patchJson('/api/v1/tickets/'.$ticket->id, ['assignee_id' => $this->stranger->id], $this->appHeaders($this->appToken($this->editor)))
            ->assertUnprocessable();
        $this->assertNull($ticket->fresh()->assigned_to);
        Notification::assertNotSentTo($this->stranger, TicketAssignedNotification::class);
    }

    public function test_the_shared_service_never_notifies_someone_without_access(): void
    {
        $ticket = $this->ticket();
        TicketActions::update($ticket, $this->editor, ['assigned_to' => $this->stranger->id]);
        Notification::assertNotSentTo($this->stranger, TicketAssignedNotification::class);

        // a watcher who lost access hears nothing about replies
        $ticket->watchers()->syncWithoutDetaching([$this->stranger->id, $this->viewer->id]);
        TicketActions::reply($ticket, $this->editor, '<p>Hi</p>', false);
        Notification::assertSentTo($this->viewer, TicketReplyNotification::class);
        Notification::assertNotSentTo($this->stranger, TicketReplyNotification::class);
    }
}
