<?php

namespace Tests\Feature\Live;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\UpdatePlanTool;
use App\Models\Plan;
use App\Support\Live\BoardLive;
use App\Support\Live\Live;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

use function Illuminate\Support\defer;

/**
 * Live plans (BRAI-152): plan changes publish `plan.updated` {revision}; issues linked to plan rows
 * publish `issues.changed` to those plans; a save that stores exactly the stored document never
 * conflicts. Plan: docs/ai/tasks/feat-live-boards.md.
 */
class LivePlansTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private Plan $plan;

    private int $seen = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        BoardLive::reset();
        Cache::forget('live:publish:paused');
        Http::fake(['127.0.0.1:6299/*' => Http::response(['delivered' => 1], 202)]);
        $this->plan = Plan::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'slug' => 'launch', 'name' => 'Top secret launch']);
        config(['collab.url' => 'wss://brainitive.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false, 'live.enabled' => true, 'live.url' => null, 'live.publish_url' => 'http://127.0.0.1:6299']);
    }

    private function settle(): void
    {
        defer()->invoke();
        $this->seen = count(Http::recorded());
    }

    /** @return Collection<int, array{channel: string, event: string, payload: array<string, mixed>}> */
    private function published(string $channel): Collection
    {
        defer()->invoke();
        $messages = collect(Http::recorded())->slice($this->seen)
            ->flatMap(fn (array $pair) => json_decode($pair[0]->body(), true)['messages'] ?? [])
            ->values();
        foreach ($messages as $message) {
            Live::validate($message['channel'], $message['event'], $message['payload']);
        }

        return $messages->where('channel', $channel)->values();
    }

    private function route(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $this->plan->slug, ...$extra]);
    }

    /** @return array<string, mixed> */
    private function document(string $name, ?int $itemId = null): array
    {
        $row = ['id' => 'r1', 'name' => $name, 'level' => 0, 'bars' => [['id' => 'b1', 'start' => '2026-11-02', 'end' => '2026-11-06', 'type' => 'bar', 'color' => '#0E83DC']]];

        return ['data' => ['rows' => [$itemId ? $row + ['itemId' => $itemId] : $row], 'dependencies' => []], 'settings' => ['zoom' => 'day']];
    }

    public function test_saving_moving_a_bar_publishes_the_new_revision_without_content(): void
    {
        $this->actingAs($this->editor)
            ->withHeader('X-Live-Client', 'tab-plan-0001')
            ->putJson($this->route('plans.save'), $this->document('Kick-off') + ['base_revision' => 0])
            ->assertOk()
            ->assertJsonPath('edit_revision', 1);

        $messages = $this->published("plan.{$this->plan->id}");
        $this->assertSame(['plan.updated'], $messages->pluck('event')->all());
        $this->assertSame(1, $messages[0]['payload']['revision']);
        $this->assertSame('tab-plan-0001', $messages[0]['payload']['client_id']);
        $this->assertSame($this->editor->id, $messages[0]['payload']['actor_id'], 'an echo is the tab and its user');
        $this->assertContains('data', $messages[0]['payload']['fields']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'Kick-off') || str_contains($request->body(), 'Top secret'));
    }

    public function test_rename_restore_mcp_and_delete_publish_too(): void
    {
        $this->actingAs($this->editor)->patch($this->route('plans.update'), ['name' => 'Renamed'])->assertRedirect();
        $renamed = $this->published("plan.{$this->plan->id}");
        $this->assertSame([['plan.updated', 0, ['name']]], $renamed->map(fn ($m) => [$m['event'], $m['payload']['revision'], $m['payload']['fields']])->all());
        $this->settle();

        BrainitiveServer::actingAs($this->editor)->tool(UpdatePlanTool::class, [
            'workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'plan' => 'launch',
            'expected_revision' => 0, 'request_id' => 'live-plan-1',
            'operations' => [['op' => 'update_row', 'row_id' => 'r2', 'name' => 'Design v2']],
        ])->assertOk();
        $mcp = $this->published("plan.{$this->plan->id}");
        $this->assertSame('plan.updated', $mcp[0]['event']);
        $this->assertSame(1, $mcp[0]['payload']['revision']);
        $this->settle();

        $this->plan->refresh();
        $version = $this->plan->versions()->value('version') ?? $this->plan->recordVersion($this->editor, 'v')->version;
        $this->settle();
        $this->actingAs($this->editor)->postJson($this->route('plans.versions.restore', [$version]))->assertOk();
        $this->assertSame(2, $this->published("plan.{$this->plan->id}")[0]['payload']['revision']);
        $this->settle();

        $admin = $this->projectMember('admin');
        $this->actingAs($admin)->delete($this->route('plans.destroy'))->assertRedirect();
        $this->assertSame(['plan.deleted'], $this->published("plan.{$this->plan->id}")->pluck('event')->all());
    }

    public function test_a_change_of_a_linked_issue_reaches_the_plans_linking_it(): void
    {
        $linked = $this->item(['title' => 'Linked issue']);
        $unlinked = $this->item();
        $this->plan->saveDocument($this->editor, $this->document('Linked issue', $linked->id)['data'], []);
        $this->settle();

        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $linked), ['due_date' => '2026-11-20'])->assertSessionHasNoErrors();
        $this->assertSame([['issues.changed', ['ids' => [$linked->id]]]],
            $this->published("plan.{$this->plan->id}")->map(fn ($m) => [$m['event'], $m['payload']])->all());
        $this->settle();

        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $unlinked), ['due_date' => '2026-11-20'])->assertSessionHasNoErrors();
        $this->assertCount(0, $this->published("plan.{$this->plan->id}"));
    }

    public function test_a_stale_save_of_the_stored_document_is_saved_and_a_different_one_conflicts(): void
    {
        $this->plan->saveDocument($this->otherEditor(), $this->document('Same')['data'], ['zoom' => 'day']);
        $revision = $this->plan->fresh()->edit_revision;

        // another editor applied the very same change (e.g. the issue sync after a live event)
        $this->actingAs($this->editor)
            ->putJson($this->route('plans.save'), $this->document('Same') + ['base_revision' => 0])
            ->assertOk()
            ->assertJsonPath('edit_revision', $revision);
        $this->assertSame($revision, $this->plan->fresh()->edit_revision, 'nothing written');

        $this->actingAs($this->editor)
            ->putJson($this->route('plans.save'), $this->document('Mine') + ['base_revision' => 0])
            ->assertStatus(409);
        $this->actingAs($this->editor)
            ->putJson($this->route('plans.save'), $this->document('Same') + ['base_revision' => 0, 'checkpoint' => true])
            ->assertStatus(409);
    }

    private function otherEditor()
    {
        return $this->projectMember('editor');
    }
}
