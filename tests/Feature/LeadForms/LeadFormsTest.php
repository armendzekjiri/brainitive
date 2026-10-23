<?php

namespace Tests\Feature\LeadForms;

use App\Jobs\DeliverLeadWebhook;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\LeadFormEvent;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\LeadConfirmationNotification;
use App\Notifications\NewLeadNotification;
use App\Support\LeadForms\LeadFormDefinition;
use App\Support\LeadForms\LeadFormTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LeadFormsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $editor;

    private User $viewer;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();

        $owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $this->workspace->addUser($owner, 'admin');
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $owner->id]);

        $this->editor = $this->member('editor');
        $this->viewer = $this->member('viewer');
        $this->outsider = User::factory()->create();
    }

    // ── definition & logic ───────────────────────────────────────────────────

    public function test_normalize_repairs_ids_keys_types_options_and_conditions(): void
    {
        $definition = LeadFormDefinition::normalize(['steps' => [
            ['id' => 'one', 'title' => 'First', 'fields' => [
                ['id' => 'dup', 'type' => 'nonsense', 'label' => 'Name', 'visible_if' => ['field' => 'later', 'op' => 'answered']],
                ['id' => 'dup', 'type' => 'choice', 'label' => 'Name', 'options' => [['label' => ''], 'x']],
                ['id' => 'mail', 'type' => 'email', 'label' => 'Email', 'required' => true],
            ]],
            ['title' => 'Second', 'visible_if' => ['field' => 'mail', 'op' => 'bogus'], 'fields' => [
                ['id' => 'later', 'type' => 'hidden', 'label' => 'Campaign', 'required' => true, 'visible_if' => ['field' => 'mail', 'op' => 'answered']],
            ]],
        ]]);

        $fields = LeadFormDefinition::fields($definition);
        $this->assertCount(4, $fields);
        $this->assertSame('short_text', $fields[0]['type']);
        $this->assertNull($fields[0]['visible_if'], 'conditions may only use earlier fields');
        $this->assertNotSame('dup', $fields[1]['id'], 'duplicate ids are replaced');
        $this->assertSame(['name', 'name_2'], [$fields[0]['key'], $fields[1]['key']]);
        $this->assertCount(1, $fields[1]['options'], 'a choice always has an option');
        $this->assertSame('email', $fields[2]['maps_to'], 'the first email field becomes the lead email');
        $this->assertFalse($fields[3]['required'], 'hidden fields are never required');
        $this->assertSame(['field' => 'mail', 'op' => 'answered', 'value' => null], $fields[3]['visible_if']);
        $this->assertNull($definition['steps'][1]['visible_if'], 'unknown operators are dropped');

        $this->expectException(ValidationException::class);
        LeadFormDefinition::normalize(['steps' => [['title' => 'Empty', 'fields' => []]]]);
    }

    public function test_hidden_steps_are_not_validated(): void
    {
        $definition = LeadFormTemplates::build('quote_calculator')['definition'];
        $base = ['f_name' => 'Jane', 'f_email' => 'jane@example.com', 'f_phone' => '+49 30 1234', 'f_postcode' => '10115'];

        // Moving: the cleaning step (area required) is skipped, the moving step is required
        $answers = LeadFormDefinition::validateAnswers($definition, $base + [
            'f_service' => 'o_moving', 'f_from_city' => 'Berlin', 'f_to_city' => 'Hamburg', 'f_details' => 'Two rooms',
            'f_area' => 'not a number', // hidden → ignored and dropped
        ]);
        $this->assertSame('Berlin', $answers['f_from_city']);
        $this->assertArrayNotHasKey('f_area', $answers);

        try {
            LeadFormDefinition::validateAnswers($definition, $base + ['f_service' => 'o_cleaning']);
            $this->fail('cleaning details are required when cleaning is picked');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('answers.f_area', $e->errors());
            $this->assertArrayHasKey('answers.f_frequency', $e->errors());
            $this->assertArrayNotHasKey('answers.f_from_city', $e->errors());
        }
    }

    public function test_every_template_builds_a_valid_form(): void
    {
        foreach (LeadFormTemplates::keys() as $key) {
            $built = LeadFormTemplates::build($key);
            $this->assertNotEmpty(LeadFormDefinition::fields($built['definition']), $key);
            $this->assertNotEmpty($built['columns'], $key);
            $this->assertSame($built['definition'], LeadFormDefinition::normalize($built['definition']), "{$key} is already normalised");
        }
        $this->assertGreaterThanOrEqual(12, count(LeadFormTemplates::keys()));
    }

    // ── app: create, build, publish ──────────────────────────────────────────

    public function test_editors_create_forms_from_templates_with_their_board(): void
    {
        $this->actingAs($this->editor)
            ->post($this->projectRoute('lead-forms.store'), ['name' => 'Demo requests', 'template' => 'demo_request'])
            ->assertRedirect(route('lead-forms.show', [$this->workspace->slug, $this->project->slug, 'demo-requests', 'tab' => 'builder']));

        $form = LeadForm::where('slug', 'demo-requests')->firstOrFail();
        $this->assertSame(LeadForm::STATUS_DRAFT, $form->status);
        $this->assertSame(['New', 'Contacted', 'Demo booked', 'Proposal', 'Won', 'Lost'], $form->columns->pluck('name')->all());
        $this->assertSame([$this->editor->id], $form->settings['notify_user_ids']);
        $this->assertSame('#4f46e5', $form->theme['primary']);

        // same name again → unique slug
        $this->actingAs($this->editor)->post($this->projectRoute('lead-forms.store'), ['name' => 'Demo requests']);
        $this->assertTrue(LeadForm::where('slug', 'demo-requests-2')->exists());

        $this->actingAs($this->viewer)->post($this->projectRoute('lead-forms.store'), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($this->outsider)->post($this->projectRoute('lead-forms.store'), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($this->editor)->post($this->projectRoute('lead-forms.store'), ['name' => 'X', 'template' => 'unknown'])->assertSessionHasErrors('template');
    }

    public function test_builder_saves_definition_theme_and_settings(): void
    {
        $form = $this->form();

        $this->actingAs($this->editor)->putJson($this->formRoute('lead-forms.update', $form), [
            'name' => 'Contact us',
            'definition' => ['steps' => [['title' => 'Hi', 'fields' => [['id' => 'f_mail', 'type' => 'email', 'label' => 'Email']]]]],
            'theme' => ['primary' => '#ff0000', 'logo_url' => 'https://cdn.example.com/logo.png'],
            'settings' => ['notify_user_ids' => [$this->viewer->id, $this->outsider->id], 'webhook_url' => 'https://hooks.example.com/x'],
        ])->assertOk()->assertJsonPath('form.name', 'Contact us')->assertJsonPath('form.theme.primary', '#ff0000');

        $form->refresh();
        $this->assertSame('f_mail', $form->definition['steps'][0]['fields'][0]['id']);
        $this->assertSame('inter', $form->theme['font'], 'other theme keys are kept');
        $this->assertSame([$this->viewer->id], $form->settings['notify_user_ids'], 'only project members are notified');
        $this->assertNotEmpty($form->webhook_secret, 'a signing secret is created with the webhook');

        $this->actingAs($this->editor)->putJson($this->formRoute('lead-forms.update', $form), ['theme' => ['primary' => 'red']])->assertJsonValidationErrors('theme.primary');
        $this->actingAs($this->editor)->putJson($this->formRoute('lead-forms.update', $form), ['theme' => ['logo_url' => 'javascript:alert(1)']])->assertJsonValidationErrors('theme.logo_url');
        $this->actingAs($this->editor)->putJson($this->formRoute('lead-forms.update', $form), ['theme' => ['evil' => 1]])->assertJsonValidationErrors('theme');
        $this->actingAs($this->editor)->putJson($this->formRoute('lead-forms.update', $form), ['settings' => ['after_submit' => 'redirect']])->assertJsonValidationErrors('settings.redirect_url');
        $this->actingAs($this->viewer)->putJson($this->formRoute('lead-forms.update', $form), ['name' => 'Mine'])->assertForbidden();
    }

    public function test_publishing_needs_a_free_valid_address_and_drafts_are_not_public(): void
    {
        $form = $this->form();
        $other = $this->form('Other');

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => []])->assertNotFound();

        $this->actingAs($this->editor)->post($this->formRoute('lead-forms.publish', $form), ['public_slug' => 'Bad Slug'])->assertSessionHasErrors('public_slug');
        $this->actingAs($this->editor)->post($this->formRoute('lead-forms.publish', $form), ['public_slug' => 'demo'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post($this->formRoute('lead-forms.publish', $other), ['public_slug' => 'demo'])->assertSessionHasErrors('public_slug');
        $this->assertTrue($form->fresh()->isPublished());

        $this->actingAs($this->editor)->delete($this->formRoute('lead-forms.unpublish', $form));
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => []])->assertNotFound();
        $this->getJson(route('lead-forms.public', 'demo'))->assertNotFound();
    }

    public function test_deleting_a_form_frees_its_address(): void
    {
        $form = $this->form(publicSlug: 'demo');

        $this->actingAs($this->editor)->delete($this->formRoute('lead-forms.destroy', $form))->assertForbidden();
        $admin = $this->member('admin');
        $this->actingAs($admin)->delete($this->formRoute('lead-forms.destroy', $form))->assertRedirect();

        $this->assertSoftDeleted($form);
        $other = $this->form('Other');
        $this->actingAs($this->editor)->post($this->formRoute('lead-forms.publish', $other), ['public_slug' => 'demo'])->assertSessionHasNoErrors();
    }

    // ── public submit ────────────────────────────────────────────────────────

    public function test_submissions_land_as_numbered_leads_in_the_first_column(): void
    {
        $form = $this->form(publicSlug: 'demo');

        $this->postJson(route('lead-forms.submit', 'demo'), [
            'answers' => $this->demoAnswers(),
            'utm' => ['source' => 'linkedin', 'campaign' => 'q4'],
            'referrer' => 'https://example.com/pricing',
            'session_id' => 'sess_1',
        ])->assertOk()->assertJsonPath('ok', true)->assertJsonPath('after_submit', 'message');

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => ['f_email' => 'b@example.com'] + $this->demoAnswers()])->assertOk();

        $leads = Lead::where('lead_form_id', $form->id)->orderBy('number')->get();
        $this->assertSame([1, 2], $leads->pluck('number')->all());
        $first = $leads[0];
        $this->assertSame($form->columns()->first()->id, $first->column_id);
        $this->assertSame(['Jane Doe', 'jane@example.com', 'Acme'], [$first->name, $first->email, $first->company]);
        $this->assertSame('o_grow_sales', $first->answers['f_goal']);
        $this->assertSame(['source' => 'linkedin', 'campaign' => 'q4'], $first->meta['utm']);
        $this->assertSame('form', $first->source);
        $this->assertSame(1, LeadFormEvent::where('type', 'submit')->count());

        Notification::assertSentTo($this->editor, NewLeadNotification::class);
        Notification::assertNotSentTo($this->viewer, NewLeadNotification::class);
    }

    public function test_invalid_submissions_are_rejected_and_the_honeypot_stores_nothing(): void
    {
        $this->form(publicSlug: 'demo');

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => ['f_goal' => 'o_grow_sales']])
            ->assertJsonValidationErrors(['answers.f_name', 'answers.f_email', 'answers.f_consent', 'answers.f_team_size']);
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => ['f_goal' => 'o_hacked'] + $this->demoAnswers()])
            ->assertJsonValidationErrors(['answers.f_goal']);
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => ['f_email' => 'nope'] + $this->demoAnswers()])
            ->assertJsonValidationErrors(['answers.f_email']);

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->demoAnswers(), 'website' => 'http://spam.example'])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(0, Lead::count());
        Notification::assertNothingSent();
    }

    public function test_confirmation_email_and_redirect_after_submit(): void
    {
        $form = $this->form(publicSlug: 'demo');
        $form->update(['settings' => ['after_submit' => 'redirect', 'redirect_url' => 'https://example.com/thanks', 'confirmation_email' => true, 'notify_user_ids' => []] + $form->settings]);

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->demoAnswers()])
            ->assertOk()->assertJsonPath('redirect_url', 'https://example.com/thanks');

        Notification::assertSentOnDemand(LeadConfirmationNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'jane@example.com');
    }

    public function test_analytics_counts_unique_sessions_per_step(): void
    {
        $form = $this->form(publicSlug: 'demo');

        foreach (['a', 'b', 'c'] as $session) {
            $this->postJson(route('lead-forms.events', 'demo'), ['session_id' => $session, 'type' => 'view'])->assertNoContent();
            $this->postJson(route('lead-forms.events', 'demo'), ['session_id' => $session, 'type' => 'view'])->assertNoContent(); // reload
        }
        $this->postJson(route('lead-forms.events', 'demo'), ['session_id' => 'a', 'type' => 'start']);
        $this->postJson(route('lead-forms.events', 'demo'), ['session_id' => 'a', 'type' => 'step', 'step' => 1]);
        $this->postJson(route('lead-forms.events', 'demo'), ['session_id' => 'a', 'type' => 'submit'])->assertJsonValidationErrors('type');
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->demoAnswers(), 'session_id' => 'a']);

        $this->actingAs($this->viewer)->getJson($this->formRoute('lead-forms.analytics', $form).'?days=7')
            ->assertOk()
            ->assertJsonPath('views', 3)
            ->assertJsonPath('starts', 1)
            ->assertJsonPath('submits', 1)
            ->assertJsonPath('conversion', 33.3)
            ->assertJsonPath('steps.0.sessions', 1)
            ->assertJsonPath('steps.1.sessions', 1)
            ->assertJsonCount(7, 'series');

        $this->actingAs($this->outsider)->getJson($this->formRoute('lead-forms.analytics', $form))->assertForbidden();
    }

    // ── webhooks ─────────────────────────────────────────────────────────────

    public function test_inbound_webhook_needs_the_token_and_accepts_keys_and_labels(): void
    {
        $form = $this->form();
        $token = $this->actingAs($this->editor)->postJson($this->formRoute('lead-forms.inbound-token', $form))->assertOk()->json('token');
        $url = route('lead-forms.inbound', $form->uuid);

        $this->postJson($url, ['email' => 'x@example.com'])->assertUnauthorized();
        $this->postJson($url, ['email' => 'x@example.com'], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

        $this->postJson($url, ['name' => 'Zap Lead', 'email' => 'zap@example.com', 'goal' => 'Save time', 'team_size' => 'o_11_50', 'unknown' => 'ignored'], ['Authorization' => "Bearer {$token}"])
            ->assertCreated()->assertJsonPath('number', 1);

        $lead = Lead::firstOrFail();
        $this->assertSame(['Zap Lead', 'zap@example.com', 'webhook'], [$lead->name, $lead->email, $lead->source]);
        $this->assertSame('o_save_time', $lead->answers['f_goal']);

        $this->postJson($url, ['email' => 'not-an-email'], ['Authorization' => "Bearer {$token}"])->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
        $this->postJson($url, ['nothing' => 'known'], ['Authorization' => "Bearer {$token}"])->assertStatus(422);

        $this->actingAs($this->editor)->deleteJson($this->formRoute('lead-forms.inbound-token.revoke', $form))->assertOk();
        $this->postJson($url, ['email' => 'x@example.com'], ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_outgoing_webhook_is_signed_and_private_hosts_are_refused(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $form = $this->form(publicSlug: 'demo');
        $form->update(['webhook_secret' => 'secret123', 'settings' => ['webhook_url' => 'https://93.184.216.34/hook'] + $form->settings]);

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->demoAnswers()])->assertOk();

        Http::assertSent(function (HttpRequest $request) {
            $payload = json_decode($request->body(), true);

            return $request->url() === 'https://93.184.216.34/hook'
                && $request->header('X-Brainitive-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), 'secret123')
                && $payload['event'] === 'lead.created'
                && $payload['lead']['email'] === 'jane@example.com'
                && $payload['lead']['answers']['goal']['display'] === 'Grow sales';
        });

        Http::fake(['*' => Http::response('ok')]);
        $form->update(['settings' => ['webhook_url' => 'http://127.0.0.1/admin'] + $form->settings]);
        (new DeliverLeadWebhook(Lead::firstOrFail()->id))->handle();
        Http::assertNothingSent();
    }

    public function test_the_webhook_job_is_queued_only_with_a_url(): void
    {
        Queue::fake();
        $form = $this->form(publicSlug: 'demo');

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->demoAnswers()]);
        Queue::assertNotPushed(DeliverLeadWebhook::class);

        $form->update(['webhook_secret' => 's', 'settings' => ['webhook_url' => 'https://example.com/hook'] + $form->settings]);
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->demoAnswers()]);
        Queue::assertPushed(DeliverLeadWebhook::class, 1);
    }

    // ── board ────────────────────────────────────────────────────────────────

    public function test_editors_work_the_board_viewers_only_read(): void
    {
        $form = $this->form(publicSlug: 'demo');
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->demoAnswers()]);
        $lead = Lead::firstOrFail();
        [$new, $contacted] = $form->columns->take(2)->all();

        $this->actingAs($this->viewer)->patch($this->leadRoute('leads.move', $form, $lead), ['column_id' => $contacted->id])->assertForbidden();
        $this->actingAs($this->editor)->patch($this->leadRoute('leads.move', $form, $lead), ['column_id' => $contacted->id, 'ids' => [$lead->id]])->assertSessionHasNoErrors();
        $this->assertSame($contacted->id, $lead->fresh()->column_id);

        $foreign = $this->form('Foreign');
        $this->actingAs($this->editor)->patch($this->leadRoute('leads.move', $form, $lead), ['column_id' => $foreign->columns()->first()->id])->assertSessionHasErrors('column_id');
        $this->actingAs($this->editor)->patch($this->leadRoute('leads.move', $foreign, $lead), ['column_id' => $foreign->columns()->first()->id])->assertNotFound();

        $this->actingAs($this->editor)->patch($this->leadRoute('leads.update', $form, $lead), ['assigned_to' => $this->outsider->id])->assertSessionHasErrors('assigned_to');
        $this->actingAs($this->editor)->patch($this->leadRoute('leads.update', $form, $lead), ['assigned_to' => $this->viewer->id, 'company' => 'Acme GmbH'])->assertSessionHasNoErrors();
        $this->assertSame([$this->viewer->id, 'Acme GmbH'], [$lead->fresh()->assigned_to, $lead->fresh()->company]);

        // deleting a column moves its leads to the first remaining column
        $this->actingAs($this->editor)->delete(route('lead-forms.columns.destroy', [$this->workspace->slug, $this->project->slug, $form->slug, $contacted->id]));
        $this->assertSame($new->id, $lead->fresh()->column_id);

        $this->actingAs($this->editor)->delete($this->leadRoute('leads.destroy', $form, $lead))->assertRedirect();
        $this->assertSoftDeleted($lead);
    }

    public function test_template_catalog_is_for_workspace_members(): void
    {
        $this->actingAs($this->viewer)->getJson(route('lead-form-templates.index', $this->workspace))
            ->assertOk()->assertJsonPath('templates.1.key', 'demo_request')->assertJsonStructure(['categories', 'templates' => [['key', 'name', 'steps', 'columns']]]);
        $this->actingAs($this->outsider)->getJson(route('lead-form-templates.index', $this->workspace))->assertForbidden();
    }

    // ── review regressions ───────────────────────────────────────────────────

    public function test_members_open_the_board_outsiders_do_not_even_after_a_type_change(): void
    {
        $form = $this->form(publicSlug: 'demo');
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->demoAnswers()])->assertOk();

        // goal was a single choice; make it multiple choice, and team size a rating
        $definition = $form->fresh()->definition;
        $definition['steps'][0]['fields'][0]['type'] = 'multi_choice';
        $definition['steps'][0]['fields'][1]['type'] = 'rating';
        $this->actingAs($this->editor)->putJson($this->formRoute('lead-forms.update', $form), ['definition' => $definition])->assertOk();

        $this->actingAs($this->viewer)->get($this->formRoute('lead-forms.show', $form))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LeadForms/Show')
                ->where('can.update', false)
                ->where('columns.0.total', 1)
                ->where('columns.0.leads.0.answers.0.display', 'Grow sales')
                ->missing('form.settings.webhook_url')
                ->missing('form.settings.notify_user_ids'));

        $this->actingAs($this->outsider)->get($this->formRoute('lead-forms.show', $form))->assertForbidden();
    }

    public function test_public_page_and_embed_render_published_forms(): void
    {
        $this->form(publicSlug: 'demo');

        $this->get(route('lead-forms.public', 'demo'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('LeadForms/Public')->where('embed', false)->missing('form.settings.webhook_url'));
        $this->get(route('lead-forms.embed', 'demo'))->assertOk()->assertHeader('Content-Security-Policy', 'frame-ancestors *');
    }

    public function test_forms_of_a_deleted_project_go_offline(): void
    {
        $form = $this->form(publicSlug: 'demo');
        $token = $this->actingAs($this->editor)->postJson($this->formRoute('lead-forms.inbound-token', $form))->json('token');
        auth()->logout();
        $this->project->delete();

        $this->get(route('lead-forms.public', 'demo'))->assertNotFound();
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->demoAnswers()])->assertNotFound();
        $this->postJson(route('lead-forms.inbound', $form->uuid), ['email' => 'x@example.com'], ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        $this->assertSame(0, Lead::count());
    }

    public function test_inbound_consent_false_is_not_recorded_as_given(): void
    {
        $form = $this->form();
        $token = $this->actingAs($this->editor)->postJson($this->formRoute('lead-forms.inbound-token', $form))->json('token');

        $this->postJson(route('lead-forms.inbound', $form->uuid), ['email' => 'a@example.com', 'consent' => false], ['Authorization' => "Bearer {$token}"])->assertCreated();
        $this->postJson(route('lead-forms.inbound', $form->uuid), ['email' => 'b@example.com', 'consent' => 'maybe'], ['Authorization' => "Bearer {$token}"])->assertStatus(422);

        $this->assertArrayNotHasKey('f_consent', Lead::firstOrFail()->answers);
    }

    public function test_only_real_addresses_become_the_lead_email_and_confirmations_are_rate_limited(): void
    {
        $form = $this->form(publicSlug: 'demo');
        $definition = $form->definition;
        $definition['steps'][1]['fields'][1]['type'] = 'short_text'; // the email question, still mapped to email
        $form->update(['definition' => LeadFormDefinition::normalize($definition), 'settings' => ['confirmation_email' => true] + $form->settings]);

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => ['f_email' => 'call me'] + $this->demoAnswers()])->assertOk();
        $this->assertNull(Lead::firstOrFail()->email);

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => ['f_name' => 'Visit https://evil.example now'] + $this->demoAnswers()])->assertOk();
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->demoAnswers()])->assertOk();
        Notification::assertSentOnDemandTimes(LeadConfirmationNotification::class, 1);
        $this->assertSame('there', LeadConfirmationNotification::safeName('Visit https://evil.example now'));
        $this->assertSame('Jane', LeadConfirmationNotification::safeName('Jane'));
    }

    public function test_viewers_cannot_publish_manage_columns_tokens_or_assets(): void
    {
        Storage::fake('public');
        $form = $this->form();
        $column = $form->columns()->first();

        $this->actingAs($this->viewer)->post($this->formRoute('lead-forms.publish', $form), ['public_slug' => 'mine'])->assertForbidden();
        $this->actingAs($this->viewer)->postJson($this->formRoute('lead-forms.inbound-token', $form))->assertForbidden();
        $this->actingAs($this->viewer)->post($this->formRoute('lead-forms.columns.store', $form), ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->viewer)->postJson($this->formRoute('lead-forms.assets', $form), ['file' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
        $this->actingAs($this->viewer)->put(route('lead-forms.columns.update', [$this->workspace->slug, $this->project->slug, $form->slug, $column->id]), ['name' => 'X'])->assertForbidden();
    }

    public function test_editors_manage_columns_and_upload_theme_images(): void
    {
        Storage::fake('public');
        $form = $this->form();

        $this->actingAs($this->editor)->post($this->formRoute('lead-forms.columns.store', $form), ['name' => 'Nurture', 'color' => '#123456'])->assertSessionHasNoErrors();
        $nurture = $form->columns()->where('name', 'Nurture')->firstOrFail();
        $this->actingAs($this->editor)->put(route('lead-forms.columns.update', [$this->workspace->slug, $this->project->slug, $form->slug, $nurture->id]), ['name' => 'Nurturing', 'is_done' => true])->assertSessionHasNoErrors();
        $this->assertTrue($nurture->fresh()->is_done);

        $ids = $form->columns()->pluck('id')->reverse()->values()->all();
        $this->actingAs($this->editor)->post($this->formRoute('lead-forms.columns.reorder', $form), ['ids' => $ids])->assertSessionHasNoErrors();
        $this->assertSame($ids, $form->columns()->pluck('id')->all());

        $url = $this->actingAs($this->editor)->postJson($this->formRoute('lead-forms.assets', $form), ['file' => UploadedFile::fake()->image('logo.png')])->assertOk()->json('url');
        $this->assertStringStartsWith('/storage/lead-forms/'.$form->uuid.'/', $url);
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
        $this->actingAs($this->editor)->postJson($this->formRoute('lead-forms.assets', $form), ['file' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')])->assertJsonValidationErrors('file');
    }

    public function test_old_analytics_events_are_pruned(): void
    {
        $form = $this->form(publicSlug: 'demo');
        $old = LeadFormEvent::create(['lead_form_id' => $form->id, 'session_id' => 'old', 'type' => 'view']);
        $old->forceFill(['created_at' => now()->subDays(120)])->save();
        LeadFormEvent::create(['lead_form_id' => $form->id, 'session_id' => 'new', 'type' => 'view']);

        $this->artisan('model:prune', ['--model' => [LeadFormEvent::class]])->assertSuccessful();
        $this->assertSame(['new'], LeadFormEvent::pluck('session_id')->all());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function form(string $name = 'Demo requests', ?string $publicSlug = null): LeadForm
    {
        $this->actingAs($this->editor)->post($this->projectRoute('lead-forms.store'), ['name' => $name, 'template' => 'demo_request']);
        $form = LeadForm::where('name', $name)->latest('id')->firstOrFail();
        if ($publicSlug) {
            $form->update(['public_slug' => $publicSlug, 'status' => LeadForm::STATUS_PUBLISHED]);
        }
        auth()->logout();

        return $form->fresh();
    }

    private function demoAnswers(): array
    {
        return [
            'f_goal' => 'o_grow_sales', 'f_team_size' => 'o_11_50', 'f_name' => 'Jane Doe', 'f_email' => 'jane@example.com',
            'f_company' => 'Acme', 'f_timeline' => 'o_this_quarter', 'f_consent' => true,
        ];
    }

    private function member(string $projectRole): User
    {
        $user = User::factory()->create();
        $this->workspace->addUser($user, 'member');
        $this->project->users()->attach($user->id, ['role' => $projectRole]);

        return $user;
    }

    private function projectRoute(string $name): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug]);
    }

    private function formRoute(string $name, LeadForm $form): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $form->slug]);
    }

    private function leadRoute(string $name, LeadForm $form, Lead $lead): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $form->slug, $lead->id]);
    }
}
