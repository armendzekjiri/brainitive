<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Helpdesk;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketResolvedNotification;
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

    private function storeRoute(): string
    {
        return route('tickets.store', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug]);
    }

    public function test_agent_creates_a_ticket_for_a_customer_with_the_form_fields(): void
    {
        Notification::fake();
        $this->helpdesk->fields()->create(['name' => 'order_number', 'label' => 'Order number', 'type' => 'text', 'is_required' => true, 'position' => 1]);
        $this->helpdesk->fields()->create(['name' => 'product', 'label' => 'Product', 'type' => 'select', 'options' => ['Web', 'App'], 'is_required' => false, 'position' => 2]);
        $this->helpdesk->update(['settings' => ['default_field_contact_name' => ['is_required' => true]]]);
        $column = $this->helpdesk->columns()->first()->id;

        // Customer email, the required name and the required custom field are enforced.
        $this->actingAs($this->admin)->post($this->storeRoute(), ['subject' => 'Printer on fire', 'column_id' => $column])
            ->assertSessionHasErrors(['contact_email', 'contact_name', 'field_order_number']);
        $this->actingAs($this->admin)->post($this->storeRoute(), [
            'subject' => 'x', 'column_id' => $column, 'contact_email' => 'a@b.c', 'contact_name' => 'A', 'field_order_number' => '1', 'field_product' => 'Fax',
        ])->assertSessionHasErrors('field_product');

        $this->actingAs($this->admin)->post($this->storeRoute(), [
            'subject' => 'Printer on fire',
            'description' => 'Called at 9:00',
            'column_id' => $column,
            'contact_email' => 'Customer@Example.com',
            'contact_name' => 'Cleo Customer',
            'field_order_number' => 'A-4711',
            'field_product' => 'Web',
            'notify_contact' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $ticket = Ticket::where('subject', 'Printer on fire')->firstOrFail();
        $this->assertSame('customer@example.com', $ticket->contact->email);
        $this->assertSame('Cleo Customer', $ticket->contact->name);
        $this->assertEqualsCanonicalizing(['A-4711', 'Web'], $ticket->fieldValues()->pluck('value')->all());
        Notification::assertSentTo($ticket->contact, TicketCreatedNotification::class);
    }

    public function test_tickets_can_only_be_assigned_to_workspace_members(): void
    {
        $this->actingAs($this->admin)->post($this->storeRoute(), [
            'subject' => 'x', 'column_id' => $this->helpdesk->columns()->first()->id, 'contact_email' => 'a@b.c',
            'assigned_to' => $this->outsider->id,
        ])->assertSessionHasErrors('assigned_to');
    }

    public function test_resolving_saves_the_message_and_emails_the_customer(): void
    {
        Notification::fake();
        $this->actingAs($this->admin)->post($this->storeRoute(), [
            'subject' => 'Login broken', 'column_id' => $this->helpdesk->columns()->first()->id, 'contact_email' => 'c@example.com', 'notify_contact' => false,
        ]);
        $ticket = Ticket::where('subject', 'Login broken')->firstOrFail();
        $done = $this->helpdesk->columns()->where('is_done_column', true)->firstOrFail();

        $this->actingAs($this->admin)->patch(route('tickets.move', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug, $ticket->id]), [
            'column_id' => $done->id,
            'position' => 0,
            'resolution_message' => "Hi,\n\nfixed <b>now</b>.",
            'notify_contact' => true,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertNotNull($ticket->completed_at);
        $reply = $ticket->replies()->firstOrFail();
        $this->assertFalse((bool) $reply->is_internal_note);
        $this->assertSame('<p>Hi,</p><p>fixed &lt;b&gt;now&lt;/b&gt;.</p>', $reply->body);
        Notification::assertSentTo($ticket->contact, TicketResolvedNotification::class, function ($n) use ($ticket) {
            $mail = $n->toMail($ticket->contact);

            return str_contains($mail->subject, 'Resolved') && $mail->actionUrl === $ticket->publicUrl();
        });
    }

    public function test_support_page_has_a_short_public_address(): void
    {
        $this->actingAs($this->admin)->put(route('helpdesks.update', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug]), [
            'name' => 'Support', 'public_slug' => 'Acme Help',
        ])->assertSessionHasErrors('public_slug');

        $this->actingAs($this->admin)->put(route('helpdesks.update', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug]), [
            'name' => 'Support', 'public_slug' => 'acme-help',
        ])->assertSessionHasNoErrors();

        auth()->logout();
        $this->get('/support/acme-help')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Widgets/HelpdeskEmbed')->where('helpdesk_uuid', $this->helpdesk->uuid)->where('page.title', 'Support'));
        $this->get('/support/nope')->assertNotFound();
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
