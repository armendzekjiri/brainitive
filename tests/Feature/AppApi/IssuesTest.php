<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemPriority;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUsage;
use App\Notifications\ItemAssignedNotification;
use App\Notifications\MentionNotification;
use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\CapturesLiveEvents;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 issues (BRAI-404): the issue dialog's rules and code (Support\Boards\Issues, shared with the
 * web), viewers read / editors write / creators + admins delete, Markdown descriptions with mentions,
 * custom fields, `updated_at` conflicts, keys, request_id retries, the storage limit.
 */
class IssuesTest extends TestCase
{
    use ActsAsAppClient;
    use CapturesLiveEvents;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
    }

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($this->appToken($user ?? $this->editor));
    }

    private function admin(): User
    {
        return User::findOrFail($this->workspace->owner_id);
    }

    // ── create ──────────────────────────────────────────────────────────────────────────────

    public function test_editors_create_issues_with_the_dialogs_fields(): void
    {
        $assignee = $this->projectMember('editor');
        $label = $this->project->labels()->create(['name' => 'ui', 'slug' => 'ui', 'color' => '#00ff00']);
        $field = ItemField::create(['project_id' => $this->project->id, 'name' => 'Effort', 'type' => 'number', 'position' => 1]);
        $priority = ItemPriority::forProject($this->project)->orderBy('order')->first();

        $response = $this->postJson("/api/v1/boards/{$this->board->id}/items", [
            'title' => 'Ship the API',
            'description' => "We **must** ship.\n\nPing @[Someone](user:{$assignee->id})",
            'assignee_id' => $assignee->id,
            'priority_id' => $priority->id,
            'label_ids' => [$label->id],
            'due_date' => '2026-11-30',
            'due_time' => '17:00',
            'story_points' => 5,
            'original_estimate' => '1h 30m',
            'fields' => [(string) $field->id => 3.5],
        ], $this->headers())->assertCreated();

        $item = Item::findOrFail($response->json('data.id'));
        $response->assertJsonPath('data.key', $item->key)
            ->assertJsonPath('data.column_id', $this->column('to-do')) // the first column
            ->assertJsonPath('data.assignee.id', $assignee->id)
            ->assertJsonPath('data.priority.id', $priority->id)
            ->assertJsonPath('data.labels.0.name', 'ui')
            ->assertJsonPath('data.due_time', '17:00')
            ->assertJsonPath('data.story_points', 5)
            ->assertJsonPath('data.original_estimate_minutes', 90)
            ->assertJsonPath('data.reporter.id', $this->editor->id)
            ->assertJsonPath('data.fields.0.name', 'Effort')
            ->assertJsonPath('data.fields.0.value', 3.5)
            ->assertJsonPath('data.watching', true)
            ->assertJsonPath('data.can', ['update' => true, 'delete' => true, 'comment' => true]);
        $this->assertStringStartsWith('BRAI-', $item->key);
        $this->assertStringContainsString('<strong>must</strong>', $item->description);
        $this->assertStringContainsString('data-mention-user-id="'.$assignee->id.'"', $item->description);
        // Markdown back out, the mention in the API's syntax
        $this->assertSame("We **must** ship.\n\nPing @[{$assignee->name}](user:{$assignee->id})", $response->json('data.description'));
        Notification::assertSentTo($assignee, MentionNotification::class);
        Notification::assertSentTo($assignee, ItemAssignedNotification::class);
    }

    public function test_request_id_retries_and_the_scrum_sprint(): void
    {
        $requestId = '2f1e8a52-0e4b-4b7f-9c43-8e6c9a1d0b11';
        $first = $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'Once', 'request_id' => $requestId], $this->headers())->assertCreated();
        $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'Once', 'request_id' => $requestId], $this->headers())
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'Other', 'request_id' => $requestId], $this->headers())
            ->assertStatus(409)->assertJsonPath('code', 'conflict');
        $this->assertSame(1, Item::where('title', 'Once')->count());

        // Scrum: the running sprint, unless sprint_id says the backlog
        $scrum = Board::factory()->create(['project_id' => $this->project->id, 'type' => 'scrum']);
        $sprint = Sprint::factory()->create(['board_id' => $scrum->id, 'status' => Sprint::STATUS_ACTIVE]);
        $this->postJson("/api/v1/boards/{$scrum->id}/items", ['title' => 'Sprint work'], $this->headers())->assertCreated()->assertJsonPath('data.sprint_id', $sprint->id)
            ->assertJsonPath('data.sprint.status', 'active');
        $this->postJson("/api/v1/boards/{$scrum->id}/items", ['title' => 'Later', 'sprint_id' => null], $this->headers())->assertCreated()->assertJsonPath('data.sprint_id', null);
    }

    public function test_create_rules_rights_and_the_storage_limit(): void
    {
        $foreignProject = Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
        $foreign = Board::factory()->create(['project_id' => $foreignProject->id]);

        // outsiders: 404 before any validation; viewers: 403
        $this->postJson("/api/v1/boards/{$foreign->id}/items", [], $this->headers())->assertNotFound();
        $this->postJson('/api/v1/boards/abc/items', [], $this->headers())->assertNotFound();
        $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'x'], $this->headers($this->viewer))->assertForbidden();

        $other = Board::factory()->create(['project_id' => $this->project->id]);
        $parent = Item::factory()->create(['board_id' => $other->id]);
        $field = ItemField::create(['project_id' => $this->project->id, 'name' => 'Effort', 'type' => 'number', 'position' => 1]);
        $this->postJson("/api/v1/boards/{$this->board->id}/items", [
            'assignee_id' => $this->outsider->id,
            'column_id' => $other->columns()->value('id'),
            'parent_id' => $parent->id,
            'type_id' => 999999,
        ], $this->headers())->assertUnprocessable()->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['title', 'assignee_id', 'column_id', 'parent_id', 'type_id']);
        $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'x', 'fields' => [(string) $field->id => 'many']], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors("fields.{$field->id}");
        $this->assertSame(0, Item::where('title', 'x')->count()); // a bad field value creates nothing

        $this->app->instance(Entitlements::class, new ConfigEntitlements(['enforced' => true, 'content_bytes' => 1000]));
        WorkspaceUsage::create(['workspace_id' => $this->workspace->id, 'content_bytes' => 1000]);
        $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'Too much'], $this->headers())
            ->assertStatus(402)->assertJsonPath('code', 'limit_reached')->assertJsonPath('limit.kind', 'content');
    }

    // ── read ────────────────────────────────────────────────────────────────────────────────

    public function test_shows_an_issue_by_id_and_by_key(): void
    {
        $parent = $this->item(['title' => 'Parent']);
        $item = $this->item(['title' => 'Child', 'parent_id' => $parent->id, 'description' => '<p>Plain <em>text</em></p>']);

        $response = $this->getJson("/api/v1/items/{$item->id}", $this->headers($this->viewer))->assertOk()
            ->assertJsonPath('data.description', 'Plain *text*')
            ->assertJsonPath('data.parent.key', $parent->key)
            ->assertJsonPath('data.can', ['update' => false, 'delete' => false, 'comment' => false])
            ->assertJsonPath('data.development.connected', false);
        $this->assertArrayHasKey('pull_requests', $response->json('data.development'));

        $this->getJson('/api/v1/items/by-key/'.strtolower($item->key), $this->headers($this->viewer))->assertOk()->assertJsonPath('data.id', $item->id);
        $this->getJson('/api/v1/items/by-key/NOPE-1', $this->headers())->assertNotFound();

        // the same key in another of the user's workspaces → 409 until ?workspace= says which
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $this->editor->id]);
        $otherWorkspace->addUser($this->editor, 'admin');
        $twin = Project::factory()->create(['workspace_id' => $otherWorkspace->id, 'key' => 'BRAI']);
        $twinBoard = Board::factory()->create(['project_id' => $twin->id]);
        $twinItem = Item::factory()->create(['board_id' => $twinBoard->id, 'key' => $item->key]);
        $this->getJson("/api/v1/items/by-key/{$item->key}", $this->headers())->assertStatus(409)->assertJsonPath('code', 'conflict');
        $this->getJson("/api/v1/items/by-key/{$item->key}?workspace={$otherWorkspace->slug}", $this->headers())->assertOk()->assertJsonPath('data.id', $twinItem->id);
        // … but someone who can only open one of them gets it directly
        $this->getJson("/api/v1/items/by-key/{$item->key}", $this->headers($this->viewer))->assertOk()->assertJsonPath('data.id', $item->id);
    }

    public function test_issues_the_user_cant_open_are_not_found(): void
    {
        $foreignProject = Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'key' => 'XYZ']);
        $foreign = Item::factory()->create(['board_id' => Board::factory()->create(['project_id' => $foreignProject->id])->id]);

        $this->getJson("/api/v1/items/{$foreign->id}", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/items/by-key/{$foreign->key}", $this->headers())->assertNotFound();
        $this->patchJson("/api/v1/items/{$foreign->id}", ['title' => ''], $this->headers())->assertNotFound();
        $this->deleteJson("/api/v1/items/{$foreign->id}", [], $this->headers())->assertNotFound();
        $this->patchJson('/api/v1/items/abc', ['title' => 'x'], $this->headers())->assertNotFound();
        $this->deleteJson('/api/v1/items/abc', [], $this->headers())->assertNotFound();
        $this->getJson("/api/v1/items/{$foreign->id}", $this->headers($this->outsider))->assertNotFound();
    }

    // ── update ──────────────────────────────────────────────────────────────────────────────

    public function test_editors_change_only_the_sent_fields(): void
    {
        $this->fakeLive();
        $label = $this->project->labels()->create(['name' => 'api', 'slug' => 'api', 'color' => '#0000ff']);
        $field = ItemField::create(['project_id' => $this->project->id, 'name' => 'Done?', 'type' => 'checkbox', 'position' => 1]);
        $item = $this->item(['title' => 'Old', 'due_date' => '2026-11-10', 'story_points' => 2]);
        $this->settleLive();

        $response = $this->patchJson("/api/v1/items/{$item->id}", [
            'title' => 'New',
            'assignee_id' => $this->editor->id,
            'label_ids' => [$label->id],
            'fields' => [(string) $field->id => true],
            'original_estimate' => '2h',
            'updated_at' => $item->updated_at->toIso8601ZuluString(),
        ], $this->headers())->assertOk()
            ->assertJsonPath('data.title', 'New')
            ->assertJsonPath('data.due_date', '2026-11-10') // untouched
            ->assertJsonPath('data.story_points', 2)
            ->assertJsonPath('data.assignee.id', $this->editor->id)
            ->assertJsonPath('data.labels.0.id', $label->id)
            ->assertJsonPath('data.fields.0.value', true)
            ->assertJsonPath('data.fields.0.display', 'Yes')
            ->assertJsonPath('data.original_estimate_minutes', 120);
        $this->assertTrue($this->livePublished('board.'.$this->board->id)->isNotEmpty());
        $this->assertSame(1, $item->activities()->where('action', 'labels_changed')->count()); // history, like the web

        // null clears, [] removes every label
        $this->patchJson("/api/v1/items/{$item->id}", ['due_date' => null, 'label_ids' => []], $this->headers())->assertOk()
            ->assertJsonPath('data.due_date', null)->assertJsonPath('data.labels', []);

        // a stale updated_at → 409, nothing changes
        $this->patchJson("/api/v1/items/{$item->id}", ['title' => 'Mine', 'updated_at' => '2020-01-01T00:00:00Z'], $this->headers())
            ->assertStatus(409)->assertJsonPath('code', 'conflict');
        $this->assertSame('New', $item->fresh()->title);
        $this->assertNotNull($response->json('data.updated_at'));
    }

    public function test_update_rules_and_rights(): void
    {
        $item = $this->item();
        $sub = $this->item(['parent_id' => $item->id]);

        $this->patchJson("/api/v1/items/{$item->id}", ['title' => 'x'], $this->headers($this->viewer))->assertForbidden();
        $this->patchJson("/api/v1/items/{$item->id}", ['title' => '', 'parent_id' => $sub->id, 'original_estimate' => 'soon'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'parent_id', 'original_estimate']);
        $this->patchJson("/api/v1/items/{$item->id}", ['fields' => ['999999' => 'x']], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('fields.999999');

        // workspace admins who aren't in the project edit too
        $this->patchJson("/api/v1/items/{$item->id}", ['title' => 'By the admin'], $this->headers($this->admin()))->assertOk();
    }

    // ── delete ──────────────────────────────────────────────────────────────────────────────

    public function test_creators_and_admins_delete_with_subtasks(): void
    {
        $mine = $this->item(['created_by' => $this->editor->id]);
        $sub = $this->item(['parent_id' => $mine->id]);
        $theirs = $this->item(['created_by' => $this->viewer->id]);

        $this->deleteJson("/api/v1/items/{$theirs->id}", [], $this->headers())->assertForbidden();
        $this->deleteJson("/api/v1/items/{$mine->id}", [], $this->headers($this->viewer))->assertForbidden();

        $this->deleteJson("/api/v1/items/{$mine->id}", [], $this->headers())->assertNoContent();
        $this->assertSoftDeleted($mine);
        $this->assertSoftDeleted($sub);
        $this->getJson("/api/v1/items/{$mine->id}", $this->headers())->assertNotFound();

        $this->deleteJson("/api/v1/items/{$theirs->id}", [], $this->headers($this->admin()))->assertNoContent();
    }

    public function test_needs_an_app_token(): void
    {
        $item = $this->item();
        $this->getJson("/api/v1/items/{$item->id}")->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson("/api/v1/items/{$item->id}", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->patchJson("/api/v1/items/{$item->id}", ['title' => 'x'], $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->postJson("/api/v1/boards/{$this->board->id}/items", ['title' => 'x'], $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
    }
}
