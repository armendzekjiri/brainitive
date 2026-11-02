<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Capture;
use App\Models\Item;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Projects\ProjectTransfer;
use Database\Seeders\ItemTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Codex review, finding 3: a retried create (same `request_id`) answers with what the first call made — but only
 * while the caller can still see it. Moved meanwhile (a project transferred to another workspace, an issue moved to
 * a project the caller isn't in) → the same 404 the read endpoint gives, never its data.
 */
class ReplayVisibilityTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
    }

    private function headers(User $user): array
    {
        return $this->appHeaders($this->appToken($user));
    }

    /** A project elsewhere the editor can't open. */
    private function hiddenBoard(): Board
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addUser($owner, 'admin');
        $project = Project::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id, 'key' => 'HIDE']);

        return Board::factory()->create(['project_id' => $project->id, 'created_by' => $owner->id]);
    }

    public function test_a_template_replay_of_a_project_transferred_out_of_reach_is_404(): void
    {
        $this->seed(ItemTypeSeeder::class);
        $creator = User::factory()->create();
        $this->workspace->addUser($creator, 'editor');
        $headers = $this->headers($creator);
        $body = ['name' => 'Relaunch', 'key' => 'REL', 'template' => 'builtin:software-development', 'start_date' => '2026-11-02', 'request_id' => (string) Str::uuid()];
        $url = "/api/v1/workspaces/{$this->workspace->slug}/projects";

        $created = $this->postJson($url, $body, $headers)->assertCreated();
        $project = Project::findOrFail($created->json('data.id'));

        // still visible: the retry answers the same project
        $this->postJson($url, $body, $headers)->assertOk()->assertJsonPath('data.id', $project->id);

        // transferred to a workspace the creator isn't in (members outside the target leave the project)
        $owner = User::factory()->create();
        $target = Workspace::factory()->create(['owner_id' => $owner->id, 'name' => 'Secret Corp']);
        $target->addUser($owner, 'admin');
        ProjectTransfer::move($project, $target);
        $this->getJson("/api/v1/projects/{$project->id}", $headers)->assertNotFound();

        $replay = $this->postJson($url, $body, $headers)->assertNotFound();
        $this->assertStringNotContainsString('Relaunch', $replay->getContent());
        $this->assertStringNotContainsString('secret-corp', $replay->getContent());
    }

    public function test_a_replayed_issue_moved_to_a_project_the_caller_cannot_open_is_404(): void
    {
        $body = ['title' => 'Quarterly numbers', 'request_id' => (string) Str::uuid()];
        $url = "/api/v1/boards/{$this->board->id}/items";
        $id = $this->postJson($url, $body, $this->headers($this->editor))->assertCreated()->json('data.id');
        $this->postJson($url, $body, $this->headers($this->editor))->assertOk()->assertJsonPath('data.id', $id);

        $hidden = $this->hiddenBoard();
        Item::whereKey($id)->update(['board_id' => $hidden->id, 'project_id' => $hidden->project_id]);
        $this->getJson("/api/v1/items/{$id}", $this->headers($this->editor))->assertNotFound();

        $replay = $this->postJson($url, $body, $this->headers($this->editor))->assertNotFound();
        $this->assertStringNotContainsString('Quarterly', $replay->getContent());
    }

    public function test_a_replayed_comment_of_an_issue_moved_out_of_reach_is_404(): void
    {
        $item = $this->item(['title' => 'Visible for now']);
        $body = ['body' => 'Salary details', 'request_id' => (string) Str::uuid()];
        $url = "/api/v1/items/{$item->id}/comments";
        $this->postJson($url, $body, $this->headers($this->editor))->assertCreated();
        $this->postJson($url, $body, $this->headers($this->editor))->assertOk();

        // the comment's issue moves away; the request names the old issue id, which the caller re-resolves …
        $hidden = $this->hiddenBoard();
        $item->update(['board_id' => $hidden->id, 'project_id' => $hidden->project_id]);
        $this->postJson($url, $body, $this->headers($this->editor))->assertNotFound();
    }

    public function test_the_models_replayed_without_an_explicit_check_have_a_view_policy(): void
    {
        // createOnce() falls back to the policy's view(); the others pass their own check (else a LogicException)
        foreach ([Item::class, TaskList::class, Page::class, Meeting::class, Capture::class] as $class) {
            $policy = Gate::getPolicyFor($class);
            $this->assertNotNull($policy, $class);
            $this->assertTrue(method_exists($policy, 'view'), $class);
        }
    }
}
