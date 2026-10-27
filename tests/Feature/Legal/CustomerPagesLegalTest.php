<?php

namespace Tests\Feature\Legal;

use App\Models\FeedbackPortal;
use App\Models\Helpdesk;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\LeadConfirmationNotification;
use App\Support\LeadForms\LeadFormOptions;
use App\Support\Legal\PublicOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Customers' public pages name the workspace as the one responsible (BRAI-156): Workspace settings →
 * Legal (name, privacy + imprint URLs), the operator line on support pages / forms / portals, and the
 * lead form consent checkbox stored with its text and time.
 */
class CustomerPagesLegalTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Project $project;

    private User $owner;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Acme Workspace']);
        $this->workspace->addUser($this->owner, 'admin');
        $this->project = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->owner->id]);

        $this->editor = User::factory()->create();
        $this->workspace->addUser($this->editor, 'member');
        $this->project->users()->attach($this->editor->id, ['role' => 'editor']);
    }

    // ── workspace settings → legal ───────────────────────────────────────────

    public function test_workspace_admin_saves_the_legal_details(): void
    {
        $this->actingAs($this->owner)->patch(route('workspaces.legal.update', $this->workspace->slug), [
            'legal_controller_name' => '  Acme GmbH ',
            'legal_privacy_url' => 'https://acme.example/privacy',
            'legal_imprint_url' => 'https://acme.example/imprint',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->workspace->refresh();
        $this->assertSame('Acme GmbH', $this->workspace->legal_controller_name);
        $this->assertSame('https://acme.example/privacy', $this->workspace->legal_privacy_url);
        $this->assertSame('https://acme.example/imprint', $this->workspace->legal_imprint_url);

        // clearing works too
        $this->actingAs($this->owner)->patch(route('workspaces.legal.update', $this->workspace->slug), [
            'legal_controller_name' => '', 'legal_privacy_url' => '', 'legal_imprint_url' => null,
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->workspace->fresh()->legal_privacy_url);
    }

    public function test_legal_urls_must_be_https(): void
    {
        foreach (['http://acme.example/privacy', 'javascript:alert(1)', 'acme.example/privacy', 'data:text/html,hi', 'https://'] as $bad) {
            $this->actingAs($this->owner)->patch(route('workspaces.legal.update', $this->workspace->slug), ['legal_privacy_url' => $bad, 'legal_imprint_url' => $bad])
                ->assertSessionHasErrors(['legal_privacy_url', 'legal_imprint_url']);
        }
        $this->assertNull($this->workspace->fresh()->legal_privacy_url);
    }

    public function test_only_workspace_admins_change_the_legal_details(): void
    {
        $this->actingAs($this->editor)->patch(route('workspaces.legal.update', $this->workspace->slug), ['legal_controller_name' => 'Hijack Ltd'])
            ->assertForbidden();
        $this->actingAs(User::factory()->create())->patch(route('workspaces.legal.update', $this->workspace->slug), ['legal_controller_name' => 'Hijack Ltd'])
            ->assertForbidden();
        $this->assertNull($this->workspace->fresh()->legal_controller_name);
    }

    public function test_operator_falls_back_to_the_workspace_name_and_never_uses_unsafe_urls(): void
    {
        // token links stay generic: they must not reveal an internal workspace name
        $generic = PublicOperator::for($this->workspace);
        $this->assertFalse($generic['named']);
        $this->assertStringNotContainsString('Acme Workspace', $generic['name']);

        $operator = PublicOperator::for($this->workspace, nameFallback: true);
        $this->assertTrue($operator['named']);
        $this->assertSame('Acme Workspace', $operator['name']);
        $this->assertNull($operator['privacy_url']);
        $this->assertFalse($operator['configured']);
        $this->assertSame(route('privacy'), $operator['platform_privacy_url']);

        // a value that slipped in another way (old data, direct DB write) is still not rendered as a link
        $this->workspace->forceFill(['legal_privacy_url' => 'javascript:alert(1)', 'legal_imprint_url' => 'http://plain.example'])->save();
        $operator = PublicOperator::for($this->workspace->fresh());
        $this->assertNull($operator['privacy_url']);
        $this->assertNull($operator['imprint_url']);
    }

    // ── public pages show who runs them ──────────────────────────────────────

    public function test_support_page_and_widget_config_name_the_workspace_and_its_links(): void
    {
        $this->setLegal();
        $helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'public_slug' => 'acme-help', 'created_by' => $this->owner->id]);

        $this->get('/support/acme-help')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Widgets/HelpdeskEmbed')
            ->where('operator.name', 'Acme GmbH')
            ->where('operator.privacy_url', 'https://acme.example/privacy')
            ->where('operator.imprint_url', 'https://acme.example/imprint'));

        $this->get(route('helpdesks.widget.embed', $helpdesk->uuid))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('operator.name', 'Acme GmbH'));

        $this->getJson(route('helpdesks.widget.form', $helpdesk->uuid))->assertOk()
            ->assertJsonPath('operator.name', 'Acme GmbH')
            ->assertJsonPath('operator.privacy_url', 'https://acme.example/privacy');
    }

    public function test_without_legal_details_the_page_names_the_workspace_and_links_the_platform_policy(): void
    {
        Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'public_slug' => 'acme-help', 'created_by' => $this->owner->id]);

        $this->get('/support/acme-help')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('operator.name', 'Acme Workspace')
            ->where('operator.privacy_url', null)
            ->where('operator.platform_privacy_url', route('privacy')));
    }

    public function test_feedback_portal_names_the_workspace(): void
    {
        $this->setLegal();
        FeedbackPortal::create(['project_id' => $this->project->id, 'enabled' => true, 'slug' => 'acme-ideas', 'title' => 'Acme ideas']);

        $this->get(route('feedback.portal', 'acme-ideas'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Feedback/Index')
            ->where('operator.name', 'Acme GmbH')
            ->where('operator.privacy_url', 'https://acme.example/privacy'));
    }

    public function test_a_portal_without_a_legal_name_does_not_reveal_the_workspace_name(): void
    {
        FeedbackPortal::create(['project_id' => $this->project->id, 'enabled' => true, 'slug' => 'acme-ideas', 'title' => 'Acme ideas']);

        $this->get(route('feedback.portal', 'acme-ideas'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('operator.named', false)->where('operator.privacy_url', null))
            ->assertDontSee('Acme Workspace', false);
    }

    public function test_lead_form_page_names_the_workspace(): void
    {
        $this->setLegal();
        $this->leadForm('demo');

        $this->get(route('lead-forms.public', 'demo'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('LeadForms/Public')
            ->where('operator.name', 'Acme GmbH')
            ->where('operator.privacy_url', 'https://acme.example/privacy')
            ->where('form.consent_text', null));
    }

    // ── lead form consent ─────────────────────────────────────────────────────

    public function test_required_consent_must_be_ticked_and_is_stored_with_its_text_and_time(): void
    {
        $this->setLegal();
        $form = $this->leadForm('demo', ['consent' => 'required', 'consent_text' => 'I agree that {organization} contacts me.']);

        $this->get(route('lead-forms.public', 'demo'))->assertInertia(fn (Assert $page) => $page
            ->where('form.consent_text', 'I agree that Acme GmbH contacts me.')
            ->where('form.settings.consent', 'required'));

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->answers()])->assertJsonValidationErrors('consent');
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->answers(), 'consent' => false])->assertJsonValidationErrors('consent');
        $this->assertSame(0, Lead::where('lead_form_id', $form->id)->count());

        $this->freezeSecond();
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->answers(), 'consent' => true])->assertOk();

        $lead = Lead::where('lead_form_id', $form->id)->sole();
        $this->assertSame('I agree that Acme GmbH contacts me.', $lead->consent_text);
        $this->assertTrue($lead->consent_at->equalTo(now()));
    }

    public function test_optional_consent_can_be_left_unticked(): void
    {
        $form = $this->leadForm('demo', ['consent' => 'optional']);

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->answers()])->assertOk();
        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => ['f_email' => 'b@example.com'] + $this->answers(), 'consent' => true])->assertOk();

        $leads = Lead::where('lead_form_id', $form->id)->orderBy('number')->get();
        $this->assertNull($leads[0]->consent_at);
        $this->assertNull($leads[0]->consent_text);
        $this->assertNotNull($leads[1]->consent_at);
        $this->assertSame(str_replace('{organization}', 'Acme Workspace', LeadFormOptions::DEFAULT_CONSENT_TEXT), $leads[1]->consent_text);
    }

    public function test_without_a_checkbox_a_claimed_consent_is_not_stored(): void
    {
        $form = $this->leadForm('demo');

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->answers(), 'consent' => true])->assertOk();

        $this->assertNull(Lead::where('lead_form_id', $form->id)->sole()->consent_at);
    }

    public function test_consent_settings_are_validated_in_the_builder(): void
    {
        $form = $this->leadForm('demo');
        $route = route('lead-forms.update', [$this->workspace->slug, $this->project->slug, $form->slug]);

        $this->actingAs($this->editor)->putJson($route, ['settings' => ['consent' => 'always']])->assertJsonValidationErrors('settings.consent');
        $this->actingAs($this->editor)->putJson($route, ['settings' => ['consent' => 'required', 'consent_text' => str_repeat('x', 1001)]])
            ->assertJsonValidationErrors('settings.consent_text');
        $this->actingAs($this->editor)->putJson($route, ['settings' => ['consent' => 'required', 'consent_text' => 'Yes, contact me.']])->assertOk();

        $this->assertSame('required', $form->fresh()->settings['consent']);
    }

    public function test_lead_confirmation_mail_names_who_sends_it(): void
    {
        $this->setLegal();
        $form = $this->leadForm('demo', ['confirmation_email' => true]);

        $this->postJson(route('lead-forms.submit', 'demo'), ['answers' => $this->answers()])->assertOk();

        Notification::assertSentOnDemand(LeadConfirmationNotification::class, function ($notification, $channels, $notifiable) {
            $text = implode("\n", $notification->toMail($notifiable)->introLines);

            return str_contains($text, 'sent by Acme GmbH using') && str_contains($text, 'https://acme.example/privacy');
        });
        $this->assertNotNull($form);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function setLegal(): void
    {
        $this->workspace->forceFill([
            'legal_controller_name' => 'Acme GmbH',
            'legal_privacy_url' => 'https://acme.example/privacy',
            'legal_imprint_url' => 'https://acme.example/imprint',
        ])->save();
    }

    /** @param array<string, mixed> $settings */
    private function leadForm(string $publicSlug, array $settings = []): LeadForm
    {
        $this->actingAs($this->editor)->post(route('lead-forms.store', [$this->workspace->slug, $this->project->slug]), ['name' => 'Demo', 'template' => 'demo_request']);
        $form = LeadForm::where('name', 'Demo')->latest('id')->firstOrFail();
        $form->update([
            'public_slug' => $publicSlug,
            'status' => LeadForm::STATUS_PUBLISHED,
            'settings' => array_merge($form->settings ?? [], $settings),
        ]);
        auth()->logout();

        return $form->fresh();
    }

    /** @return array<string, mixed> */
    private function answers(): array
    {
        return [
            'f_goal' => 'o_grow_sales', 'f_team_size' => 'o_11_50', 'f_name' => 'Jane Doe', 'f_email' => 'jane@example.com',
            'f_company' => 'Acme', 'f_timeline' => 'o_this_quarter', 'f_consent' => true,
        ];
    }
}
