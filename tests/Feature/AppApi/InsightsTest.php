<?php

namespace Tests\Feature\AppApi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 read-only summaries (BRAI-423): Insights tabs (workspace admins only, as the web), a project's
 * health (metrics + status updates) and its workload grid (project members) through the web's Support classes.
 */
class InsightsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->owner = User::find($this->workspace->owner_id);
    }

    public function test_insights_tabs_for_workspace_admins(): void
    {
        $this->item(['title' => 'Counted', 'assigned_to' => $this->editor->id]);
        $admin = $this->appHeaders($this->appToken($this->owner));

        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/insights/overview", $admin)->assertOk()
            ->assertJsonPath('data.tab', 'overview')
            ->assertJsonPath('data.filters.period', 'last_30')
            ->assertJsonStructure(['data' => ['insights' => ['kpis' => ['created', 'completed', 'open', 'overdue']], 'projects']]);
        foreach (['team', 'projects', 'helpdesk', 'usage'] as $tab) {
            $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/insights/{$tab}?period=last_90&project={$this->project->id}", $admin)->assertOk()
                ->assertJsonPath('data.tab', $tab)->assertJsonPath('data.filters.project', $this->project->id);
        }
        $team = $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/insights/team", $admin)->assertOk();
        $this->assertStringNotContainsString($this->editor->email, $team->getContent());

        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/insights/secrets", $admin)->assertNotFound();
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/insights/overview?period=forever", $admin)->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/insights/overview", $this->appHeaders($this->appToken($this->editor)))->assertForbidden();
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/insights/overview", $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
    }

    public function test_project_health_for_members(): void
    {
        $this->item(['title' => 'Late', 'due_date' => now()->subDays(2)->toDateString()]);
        $this->project->healthReports()->create([
            'status' => 'at_risk', 'summary' => 'Slipping', 'created_by' => $this->owner->id, 'as_of' => now(), 'sections' => [], 'metrics' => [],
            'period_start' => now()->subDays(13)->toDateString(), 'period_end' => now()->toDateString(), 'timezone' => 'UTC',
        ]);
        $viewer = $this->appHeaders($this->appToken($this->viewer));

        $this->getJson("/api/v1/projects/{$this->project->id}/health", $viewer)->assertOk()
            ->assertJsonPath('data.to', now($this->viewer->localTimezone())->toDateString())
            ->assertJsonPath('data.metrics.values.overdue', 1)
            ->assertJsonPath('data.reports.0.status', 'at_risk')
            ->assertJsonPath('data.can_create', false);
        $this->getJson("/api/v1/projects/{$this->project->id}/health?from=2026-01-10&to=2026-01-01", $viewer)->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson("/api/v1/projects/{$this->project->id}/health", $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
    }

    public function test_workload_grid_for_members(): void
    {
        $viewer = $this->appHeaders($this->appToken($this->viewer));

        $res = $this->getJson("/api/v1/projects/{$this->project->id}/workload?weeks=4&from=2026-11-12", $viewer)->assertOk()
            ->assertJsonPath('data.from', '2026-11-09')
            ->assertJsonPath('data.weeks_count', 4)
            ->assertJsonCount(4, 'data.weeks')
            ->assertJsonPath('data.can_plan', false);
        $this->assertContains($this->editor->id, array_column($res->json('data.members'), 'id'));
        $this->getJson("/api/v1/projects/{$this->project->id}/workload?weeks=5", $viewer)->assertUnprocessable()->assertJsonValidationErrors('weeks');
        $this->getJson("/api/v1/projects/{$this->project->id}/workload", $this->appHeaders($this->appToken($this->outsider)))->assertNotFound();
    }

    public function test_tokens_of_other_clients_and_no_token_are_401(): void
    {
        $this->getJson("/api/v1/projects/{$this->project->id}/health")->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson("/api/v1/workspaces/{$this->workspace->slug}/insights/overview", $this->appHeaders($this->passportToken($this->owner)))->assertUnauthorized();
    }
}
