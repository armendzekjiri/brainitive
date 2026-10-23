<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Helpdesk;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Helpdesks\HelpdeskTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Helpdesk templates (BRAI-92) and the configurable, server-validated public form.
 */
class HelpdeskTemplatesTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
    }

    private function create(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->editor)->post(route('helpdesks.store', [$this->workspace->slug, $this->project->slug]), $data + ['name' => 'Desk']);
    }

    private function helpdesk(string $name = 'Desk'): Helpdesk
    {
        return Helpdesk::where('project_id', $this->project->id)->where('name', $name)->with(['columns', 'fields'])->firstOrFail();
    }

    private function submit(Helpdesk $helpdesk, array $data)
    {
        return $this->postJson(route('helpdesks.tickets.store-public', $helpdesk->uuid), $data);
    }

    public function test_every_template_creates_its_columns_fields_and_form_texts(): void
    {
        foreach (HelpdeskTemplates::all() as $template) {
            $name = 'Desk '.$template['key'];
            $this->create(['name' => $name, 'template' => $template['key']])->assertSessionHasNoErrors()->assertRedirect();
            $helpdesk = $this->helpdesk($name);
            $built = HelpdeskTemplates::build($template['key']);

            $this->assertSame(array_column($built['columns'], 0), $helpdesk->columns->pluck('name')->all(), "{$template['key']}: columns");
            $this->assertTrue($helpdesk->columns->contains('is_done_column', true), "{$template['key']}: has a done column");
            $this->assertSame(array_column($built['fields'], 0), $helpdesk->fields->pluck('label')->all(), "{$template['key']}: fields");
            foreach ($helpdesk->fields as $field) {
                $this->assertContains($field->type, ['text', 'rich_text', 'select', 'checkbox', 'number', 'radio', 'date', 'checkboxes'], "{$template['key']}: {$field->label}");
                if (in_array($field->type, ['select', 'radio', 'checkboxes'], true)) {
                    $this->assertNotEmpty($field->options, "{$template['key']}: {$field->label} has options");
                }
                $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $field->name);
            }
            $this->assertSame($built['settings']['form_title'], $helpdesk->settings['form_title']);

            // The public form config carries the template's texts
            $config = $this->getJson(route('helpdesks.widget.form', $helpdesk->uuid))->assertOk()->json();
            $this->assertSame($built['settings']['form_title'], $config['texts']['form_title']);
            $this->assertSame(! empty($built['settings']['default_field_subject']['hidden']), $config['subject']['hidden']);
        }
        $this->assertGreaterThanOrEqual(15, count(HelpdeskTemplates::all()));
    }

    public function test_blank_helpdesk_keeps_todays_defaults(): void
    {
        $this->create([])->assertSessionHasNoErrors();
        $helpdesk = $this->helpdesk();

        $this->assertSame(['Open', 'In Progress', 'Resolved'], $helpdesk->columns->pluck('name')->all());
        $this->assertCount(0, $helpdesk->fields);
        $config = $this->getJson(route('helpdesks.widget.form', $helpdesk->uuid))->json();
        $this->assertSame('Contact support', $config['texts']['form_title']);
        $this->assertSame(['label' => 'Subject', 'hidden' => false], $config['subject']);
        $this->assertSame('portal', $config['texts']['after_submit']);
    }

    public function test_unknown_template_and_viewers_are_refused(): void
    {
        $this->create(['template' => 'nope'])->assertSessionHasErrors('template');
        $this->create(['template' => 'contact-form'], $this->viewer)->assertForbidden();
        $this->assertSame(0, Helpdesk::count());
    }

    public function test_catalog_lists_templates_with_their_form(): void
    {
        $response = $this->actingAs($this->editor)->getJson(route('helpdesk-templates.index', $this->workspace))->assertOk();

        $this->assertSame(array_keys(HelpdeskTemplates::CATEGORIES), array_column($response->json('categories'), 'key'));
        $event = collect($response->json('templates'))->firstWhere('key', 'event-registration');
        $this->assertSame('Register for the event', $event['form']['title']);
        $this->assertNull($event['form']['subject'], 'Registration has no subject field.');
        $this->assertSame('Ticket type', $event['fields'][0]['label']);
        $this->assertSame('Checked in', $event['columns'][2]['name']);
        $this->assertTrue($event['columns'][2]['is_done']);
    }

    public function test_event_registration_validates_on_the_server_and_fills_the_subject(): void
    {
        $this->create(['template' => 'event-registration'])->assertSessionHasNoErrors();
        $helpdesk = $this->helpdesk();
        $ticketType = $helpdesk->fields->firstWhere('label', 'Ticket type');
        $diet = $helpdesk->fields->firstWhere('label', 'Dietary needs');

        // Required custom field and required name, checked by the server
        $this->submit($helpdesk, ['contact_email' => 'ada@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_name', 'field_'.$ticketType->name]);
        // Choices must come from the field's options
        $this->submit($helpdesk, ['contact_email' => 'ada@example.com', 'contact_name' => 'Ada', 'field_'.$ticketType->name => 'Backstage'])
            ->assertStatus(422)->assertJsonValidationErrors(['field_'.$ticketType->name]);
        $this->submit($helpdesk, ['contact_email' => 'ada@example.com', 'contact_name' => 'Ada', 'field_'.$ticketType->name => 'VIP', 'field_'.$diet->name => json_encode(['Vegan', 'Pizza'])])
            ->assertStatus(422)->assertJsonValidationErrors(['field_'.$diet->name.'.1']);

        $this->submit($helpdesk, [
            'contact_email' => 'ada@example.com',
            'contact_name' => 'Ada Lovelace',
            'field_'.$ticketType->name => 'VIP',
            'field_'.$diet->name => json_encode(['Vegan', 'Gluten-free']),
        ])->assertOk()->assertJsonPath('message', 'You’re registered! A confirmation is on its way to your inbox.');

        $ticket = Ticket::firstOrFail();
        $this->assertSame('Registration — Ada Lovelace', $ticket->subject);
        $this->assertSame('Registered', $ticket->column->name, 'New submissions land in the first column.');
        $this->assertSame('VIP', $ticket->fieldValues()->where('helpdesk_field_id', $ticketType->id)->value('value'));
        $this->assertSame(['Vegan', 'Gluten-free'], json_decode($ticket->fieldValues()->where('helpdesk_field_id', $diet->id)->value('value'), true));
    }

    public function test_numbers_dates_and_required_consent_are_checked(): void
    {
        $this->create(['template' => 'newsletter-waitlist'])->assertSessionHasNoErrors();
        $newsletter = $this->helpdesk();
        $consent = $newsletter->fields->firstWhere('type', 'checkbox');
        $this->submit($newsletter, ['contact_email' => 'a@example.com'])->assertStatus(422)->assertJsonValidationErrors(['field_'.$consent->name]);
        $this->submit($newsletter, ['contact_email' => 'a@example.com', 'field_'.$consent->name => '1'])->assertOk();

        $this->create(['name' => 'Booking', 'template' => 'appointment-booking'])->assertSessionHasNoErrors();
        $booking = $this->helpdesk('Booking');
        $base = ['contact_email' => 'b@example.com', 'contact_name' => 'Bo'];
        foreach ($booking->fields as $field) {
            if ($field->is_required && $field->type !== 'date') {
                $base['field_'.$field->name] = $field->options[0];
            }
        }
        $date = $booking->fields->firstWhere('type', 'date');
        $this->submit($booking, $base + ['field_'.$date->name => 'next tuesday-ish'])->assertStatus(422)->assertJsonValidationErrors(['field_'.$date->name]);
        $this->submit($booking, $base + ['field_'.$date->name => '2026-11-20'])->assertOk();
    }

    public function test_a_classic_support_form_still_needs_a_subject(): void
    {
        $this->create(['template' => 'customer-support'])->assertSessionHasNoErrors();
        $helpdesk = $this->helpdesk();
        $category = $helpdesk->fields->firstWhere('label', 'Category');

        $this->submit($helpdesk, ['contact_email' => 'c@example.com', 'description' => '<p>Help</p>', 'field_'.$category->name => 'Billing'])
            ->assertStatus(422)->assertJsonValidationErrors(['subject']);
        $this->submit($helpdesk, ['contact_email' => 'c@example.com', 'subject' => 'Invoice', 'description' => '<p>Help</p>', 'field_'.$category->name => 'Billing'])
            ->assertOk();
    }

    public function test_admins_can_edit_the_form_texts(): void
    {
        $this->create([])->assertSessionHasNoErrors();
        $helpdesk = $this->helpdesk();

        $this->actingAs($this->editor)->put(route('helpdesks.update', [$this->workspace->slug, $this->project->slug, $helpdesk->slug]), [
            'name' => 'Desk',
            'settings' => ['form_title' => 'Say hello', 'after_submit' => 'message', 'default_field_subject' => ['hidden' => true]],
        ])->assertSessionHasNoErrors();

        $config = $this->getJson(route('helpdesks.widget.form', $helpdesk->uuid))->json();
        $this->assertSame('Say hello', $config['texts']['form_title']);
        $this->assertSame('message', $config['texts']['after_submit']);
        $this->assertTrue($config['subject']['hidden']);

        $this->actingAs($this->editor)->put(route('helpdesks.update', [$this->workspace->slug, $this->project->slug, $helpdesk->slug]), [
            'name' => 'Desk', 'settings' => ['after_submit' => 'somewhere'],
        ])->assertSessionHasErrors('settings.after_submit');
    }
}
