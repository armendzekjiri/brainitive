<?php

namespace Tests\Feature\AppApi;

use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\LeadFormEvent;
use App\Support\LeadForms\LeadFormTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 leads, member side (BRAI-421): a project's lead forms with their pipeline, the leads per column
 * (answers, UTM, consent as the web's lead sheet), and moving / editing / assigning a lead through LeadActions
 * (shared with the web). Viewers read, editors work the leads; other workspaces' forms are 404.
 */
class LeadsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private LeadForm $form;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $built = LeadFormTemplates::build('demo_request');
        $this->form = $this->project->leadForms()->create([
            'name' => 'Demo requests', 'slug' => 'demo-requests', 'status' => LeadForm::STATUS_PUBLISHED, 'public_slug' => 'demo-acme',
            'definition' => $built['definition'], 'theme' => $built['theme'], 'settings' => $built['settings'] + ['webhook_url' => 'https://hooks.example.test/secret'],
            'position' => 1, 'created_by' => $this->editor->id, 'webhook_secret' => 'whsec_123',
        ]);
        foreach ($built['columns'] as $i => $column) {
            $this->form->columns()->create($column + ['position' => $i]);
        }
        $this->token = $this->appToken($this->editor);
    }

    private function columnId(int $index): int
    {
        return $this->form->columns()->get()[$index]->id;
    }

    private function lead(array $attributes = []): Lead
    {
        static $number = 0;

        return Lead::create($attributes + [
            'lead_form_id' => $this->form->id, 'column_id' => $this->columnId(0), 'number' => ++$number,
            'name' => 'Jane Doe', 'email' => 'jane@example.com', 'company' => 'Acme', 'source' => 'form',
            'answers' => ['f_goal' => 'o_grow_sales', 'f_company' => 'Acme'],
            'meta' => ['utm' => ['utm_source' => 'newsletter', 'utm_campaign' => 'fall'], 'referrer' => 'https://ref.example.test/', 'ip' => '203.0.113.9'],
            'position' => 0,
        ]);
    }

    public function test_lead_forms_with_their_pipeline(): void
    {
        $this->lead();
        $this->lead(['column_id' => $this->columnId(1)]);

        $res = $this->getJson("/api/v1/projects/{$this->project->id}/lead-forms", $this->appHeaders($this->token))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Demo requests')
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.leads_count', 2)
            ->assertJsonPath('data.0.columns.0.leads_count', 1)
            ->assertJsonPath('data.0.public_url', route('lead-forms.public', 'demo-acme'))
            ->assertJsonPath('data.0.can.manage_leads', true);
        $this->assertStringNotContainsString('whsec_123', $res->getContent());
        $this->assertStringNotContainsString('hooks.example.test', $res->getContent());

        $this->getJson("/api/v1/projects/{$this->project->id}/lead-forms", $this->appHeaders($this->appToken($this->viewer)))->assertOk()
            ->assertJsonPath('data.0.can.manage_leads', false);
        $this->getJson("/api/v1/projects/{$this->project->id}/lead-forms", $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
    }

    public function test_leads_per_column_and_one_lead(): void
    {
        $a = $this->lead(['position' => 1, 'name' => 'A']);
        $b = $this->lead(['position' => 0, 'name' => 'B', 'assigned_to' => $this->editor->id]);
        $this->lead(['column_id' => $this->columnId(1), 'name' => 'C']);
        $headers = $this->appHeaders($this->token);

        $first = $this->getJson("/api/v1/lead-forms/{$this->form->id}/leads?column={$this->columnId(0)}&limit=1", $headers)->assertOk()
            ->assertJsonPath('data.0.id', $b->id);
        $this->getJson("/api/v1/lead-forms/{$this->form->id}/leads?column={$this->columnId(0)}&limit=1&cursor=".urlencode($first->json('next_cursor')), $headers)
            ->assertOk()->assertJsonPath('data.0.id', $a->id)->assertJsonPath('next_cursor', null);
        $this->getJson("/api/v1/lead-forms/{$this->form->id}/leads", $headers)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/lead-forms/{$this->form->id}/leads?assignee=me", $headers)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.assignee.id', $this->editor->id)->assertJsonMissingPath('data.0.assignee.email');

        $res = $this->getJson("/api/v1/leads/{$a->id}", $this->appHeaders($this->appToken($this->viewer)))->assertOk()
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.utm.utm_source', 'newsletter')
            ->assertJsonPath('data.referrer', 'https://ref.example.test/');
        $this->assertNotEmpty($res->json('data.answers'));
        $this->assertStringNotContainsString('203.0.113.9', $res->getContent());

        $this->getJson("/api/v1/leads/{$a->id}", $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
        $this->getJson("/api/v1/lead-forms/{$this->form->id}/leads", $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
        $this->getJson('/api/v1/leads/x', $headers)->assertNotFound();
    }

    public function test_move_edit_and_assign_a_lead(): void
    {
        $lead = $this->lead();
        $first = $this->lead(['column_id' => $this->columnId(1), 'position' => 0]);
        $second = $this->lead(['column_id' => $this->columnId(1), 'position' => 1]);
        $headers = $this->appHeaders($this->token);

        $this->patchJson("/api/v1/leads/{$lead->id}", ['column_id' => $this->columnId(1), 'position' => 1, 'company' => 'Acme GmbH', 'assignee_id' => $this->viewer->id], $headers)->assertOk()
            ->assertJsonPath('data.column_id', $this->columnId(1))
            ->assertJsonPath('data.company', 'Acme GmbH')
            ->assertJsonPath('data.assignee.id', $this->viewer->id);
        $this->assertSame([$first->id, $lead->id, $second->id], Lead::where('column_id', $this->columnId(1))->orderBy('position')->pluck('id')->all());

        $other = LeadForm::create(['project_id' => $this->project->id, 'name' => 'Other', 'slug' => 'other', 'status' => LeadForm::STATUS_DRAFT, 'definition' => ['version' => 1, 'steps' => []], 'created_by' => $this->editor->id]);
        $foreignColumn = $other->columns()->create(['name' => 'New', 'slug' => 'new', 'position' => 0]);
        $this->patchJson("/api/v1/leads/{$lead->id}", ['column_id' => $foreignColumn->id], $headers)->assertUnprocessable()->assertJsonValidationErrors('column_id');
        $this->patchJson("/api/v1/leads/{$lead->id}", ['assignee_id' => $this->outsider->id], $headers)->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $this->patchJson("/api/v1/leads/{$lead->id}", ['email' => 'nope'], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson("/api/v1/leads/{$lead->id}", ['company' => 'x'], $this->appHeaders($this->appToken($this->viewer)))->assertForbidden();
        $this->patchJson("/api/v1/leads/{$lead->id}", ['company' => 'x'], $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
        $this->assertSame('Acme GmbH', $lead->fresh()->company);
    }

    public function test_form_analytics(): void
    {
        foreach ([['s1', 'view'], ['s1', 'start'], ['s1', 'submit'], ['s2', 'view']] as [$session, $type]) {
            LeadFormEvent::create(['lead_form_id' => $this->form->id, 'session_id' => $session, 'type' => $type, 'step' => $type === 'start' ? 0 : null]);
        }
        $this->lead();

        $this->getJson("/api/v1/lead-forms/{$this->form->id}/analytics?days=7", $this->appHeaders($this->appToken($this->viewer)))->assertOk()
            ->assertJsonPath('data.days', 7)
            ->assertJsonPath('data.views', 2)
            ->assertJsonPath('data.submits', 1)
            ->assertJsonPath('data.conversion', 50)
            ->assertJsonPath('data.leads', 1)
            ->assertJsonCount(7, 'data.series');
        $this->getJson("/api/v1/lead-forms/{$this->form->id}/analytics?days=5", $this->appHeaders($this->token))->assertUnprocessable()->assertJsonValidationErrors('days');
        $this->getJson("/api/v1/lead-forms/{$this->form->id}/analytics", $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
    }

    public function test_tokens_of_other_clients_and_no_token_are_401(): void
    {
        $this->getJson("/api/v1/projects/{$this->project->id}/lead-forms")->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson("/api/v1/lead-forms/{$this->form->id}/leads", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
    }
}
