<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Helpdesk;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Agents (project / workspace admins) working on tickets: creating one in the app and
 * opening the ticket link from the "new ticket" email / notification.
 */
class HelpdeskAgentTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected Helpdesk $helpdesk;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->admin = User::find($this->workspace->owner_id);
        $this->project->users()->attach($this->admin->id, ['role' => 'admin']);
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $this->admin->id]);
    }

    public function test_agent_creates_a_ticket_without_a_customer_contact(): void
    {
        // The in-app form sends no contact: ticket_contact_id was NOT NULL (500 on PostgreSQL).
        $this->actingAs($this->admin)
            ->post(route('tickets.store', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug]), [
                'subject' => 'Internal follow-up',
                'column_id' => $this->helpdesk->columns()->first()->id,
                'priority_id' => null,
                'assigned_to' => null,
                'description' => null,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', ['subject' => 'Internal follow-up', 'ticket_contact_id' => null]);
    }

    public function test_agent_creates_a_ticket_in_the_app(): void
    {
        $this->actingAs($this->admin)
            ->post(route('tickets.store', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug]), [
                'subject' => 'Printer on fire',
                'description' => '<p>Help</p>',
                'column_id' => $this->helpdesk->columns()->first()->id,
                'contact_email' => 'customer@example.com',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', ['subject' => 'Printer on fire']);
    }

    public function test_agent_opens_the_ticket_link_from_the_new_ticket_notification(): void
    {
        Notification::fake();
        $this->postJson(route('helpdesks.tickets.store-public', $this->helpdesk->uuid), [
            'subject' => 'From the portal',
            'description' => 'Hello',
            'contact_email' => 'customer@example.com',
        ])->assertOk();

        $ticket = Ticket::where('subject', 'From the portal')->firstOrFail();
        $sent = Notification::sent($this->admin, TicketCreatedNotification::class)->first();
        $url = $sent->toMail($this->admin)->actionUrl;

        // It used to render a page component that doesn't exist (Vite manifest error → 500).
        $this->actingAs($this->admin)->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Helpdesks/Show')->where('openTicketId', $ticket->id));
        $this->assertFileExists(resource_path('js/Pages/Helpdesks/Show.tsx'));
    }
}
