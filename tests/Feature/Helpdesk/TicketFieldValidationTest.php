<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Helpdesk;
use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Codex web review K: a ticket's custom field values are validated by the field's type — the same rules for the
 * public form, an agent's new ticket and an edit in the ticket panel.
 */
class TicketFieldValidationTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected Helpdesk $helpdesk;

    protected Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $admin = User::find($this->workspace->owner_id);
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $admin->id]);
        $fields = [
            ['amount', 'number', null], ['due', 'date', null], ['product', 'select', ['Web', 'App']],
            ['tags', 'checkboxes', ['A', 'B']], ['urgent', 'checkbox', null], ['note', 'text', null],
        ];
        foreach ($fields as $i => [$name, $type, $options]) {
            $this->helpdesk->fields()->create(['name' => $name, 'label' => ucfirst($name), 'type' => $type, 'options' => $options, 'is_required' => false, 'position' => $i]);
        }
        $contact = TicketContact::create(['email' => 'c@example.com', 'uuid' => (string) str()->uuid()]);
        $this->ticket = $this->helpdesk->tickets()->create([
            'subject' => 'Help', 'column_id' => $this->helpdesk->columns()->first()->id, 'ticket_contact_id' => $contact->id,
            'key' => $this->project->getNextTicketKey(), 'position' => 1,
        ]);
    }

    private function patchFields(array $values)
    {
        return $this->actingAs($this->editor)->patch(route('tickets.fields.update', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug, $this->ticket->id]), $values);
    }

    private function stored(string $name): ?string
    {
        return $this->ticket->fieldValues()->whereHas('field', fn ($q) => $q->where('name', $name))->value('value');
    }

    public function test_invalid_values_are_refused_and_nothing_changes(): void
    {
        $this->patchFields(['amount' => '12'])->assertSessionHasNoErrors();

        foreach ([
            ['amount' => 'not-a-number'], ['due' => '31/12/2026'], ['product' => 'Fax'],
            ['tags' => '["A","Z"]'], ['urgent' => 'maybe'], ['note' => str_repeat('x', 10001)],
        ] as $values) {
            $this->patchFields($values)->assertSessionHasErrors(array_key_first($values));
        }
        $this->assertSame('12', $this->stored('amount'));
        $this->assertNull($this->stored('product'));
        $this->assertNull($this->stored('tags'));
    }

    public function test_valid_values_are_stored_in_the_ticket_format(): void
    {
        $this->patchFields(['amount' => '12.5', 'due' => '2026-12-31', 'product' => 'App', 'tags' => '["B","A"]', 'urgent' => 'true', 'note' => 'Hi'])
            ->assertSessionHasNoErrors();

        $this->assertSame('12.5', $this->stored('amount'));
        $this->assertSame('2026-12-31', $this->stored('due'));
        $this->assertSame('App', $this->stored('product'));
        $this->assertSame('["B","A"]', $this->stored('tags'));
        $this->assertSame('1', $this->stored('urgent'));

        // an empty value clears an optional field
        $this->patchFields(['note' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->stored('note'));
    }

    public function test_an_agents_new_ticket_checks_the_same_rules(): void
    {
        $this->actingAs($this->editor)->post(route('tickets.store', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug]), [
            'subject' => 'x', 'column_id' => $this->helpdesk->columns()->first()->id, 'contact_email' => 'a@b.c',
            'field_tags' => ['Z'],
        ])->assertSessionHasErrors('field_tags.0');
    }
}
