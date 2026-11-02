<?php

namespace Tests\Feature\AppApi;

use App\Models\Capture;
use App\Models\Item;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 captures inbox (BRAI-398): the owner's captures only (others' → 404), the web's states,
 * search, archive → delete rule, the convert dialog (preview + CaptureConversion, idempotent, the
 * destination's own policy), the destinations picker and request_id retries.
 */
class CapturesTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->token = $this->appToken($this->editor);
    }

    private function capture(array $attributes = [], ?User $user = null): Capture
    {
        return Capture::factory()->create($attributes + ['user_id' => ($user ?? $this->editor)->id]);
    }

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($user ? $this->appToken($user) : $this->token);
    }

    public function test_lists_the_owners_captures_by_state_newest_first(): void
    {
        $old = $this->capture(['text' => 'Old idea', 'captured_at' => Carbon::parse('2026-11-01 08:00')]);
        $new = $this->capture(['text' => 'New idea https://example.com', 'captured_at' => Carbon::parse('2026-11-02 08:00')]);
        $archived = $this->capture(['text' => 'Gone'])->forceFill(['archived_at' => now()]);
        $archived->save();
        $this->capture(['text' => 'Someone else'], $this->viewer);

        $response = $this->getJson('/api/v1/captures', $this->headers())->assertOk()->assertJsonPath('next_cursor', null);
        $this->assertSame([$new->uuid, $old->uuid], array_column($response->json('data'), 'id'));
        $this->assertSame([
            'id', 'text', 'url', 'intended_type', 'source', 'state', 'captured_at', 'processed_at', 'archived_at', 'updated_at', 'target',
        ], array_keys($response->json('data.0')));
        $response->assertJsonPath('data.0.state', 'inbox')->assertJsonPath('data.0.target', null);

        $this->assertSame([$archived->uuid], array_column($this->getJson('/api/v1/captures?state=archived', $this->headers())->json('data'), 'id'));
        $this->assertSame([$new->uuid], array_column($this->getJson('/api/v1/captures?q=example.com', $this->headers())->json('data'), 'id'));
        $this->getJson('/api/v1/captures?state=nope', $this->headers())->assertUnprocessable();

        $page = $this->getJson('/api/v1/captures?limit=1', $this->headers())->assertOk();
        $this->assertSame([$new->uuid], array_column($page->json('data'), 'id'));
        $next = $this->getJson('/api/v1/captures?limit=1&cursor='.urlencode($page->json('next_cursor')), $this->headers())->assertOk();
        $this->assertSame([$old->uuid], array_column($next->json('data'), 'id'));

        $this->getJson('/api/v1/captures/counts', $this->headers())->assertOk()->assertExactJson(['data' => ['inbox' => 2, 'processed' => 0, 'archived' => 1]]);
    }

    public function test_saves_a_capture_named_after_the_app_and_retries_with_request_id(): void
    {
        $created = $this->postJson('/api/v1/captures', ['text' => "Idea from the tray\nmore", 'url' => 'https://brainitive.app', 'intended_type' => 'task'], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.state', 'inbox')
            ->assertJsonPath('data.source', 'Brainitive Desktop')
            ->assertJsonPath('data.intended_type', 'task');
        $this->assertSame($this->editor->id, Capture::where('uuid', $created->json('data.id'))->value('user_id'));

        $this->postJson('/api/v1/captures', ['text' => 'Shared', 'source' => 'Safari'], $this->headers())->assertCreated()->assertJsonPath('data.source', 'Safari');

        $payload = ['text' => 'Only once', 'request_id' => '9b1c2d3e-4f5a-4b6c-8d7e-9f0a1b2c3d4e'];
        $id = $this->postJson('/api/v1/captures', $payload, $this->headers())->assertCreated()->json('data.id');
        $this->postJson('/api/v1/captures', $payload, $this->headers())->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, Capture::where('text', 'Only once')->count());

        $this->postJson('/api/v1/captures', ['text' => '', 'url' => 'ftp://nope'], $this->headers())
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['text', 'url']);
    }

    public function test_edits_archives_restores_and_deletes_only_archived_captures(): void
    {
        $capture = $this->capture(['text' => 'Draft', 'url' => 'https://a.example']);

        $this->patchJson("/api/v1/captures/{$capture->uuid}", ['text' => 'Better'], $this->headers())
            ->assertOk()->assertJsonPath('data.text', 'Better')->assertJsonPath('data.url', 'https://a.example');
        $this->patchJson("/api/v1/captures/{$capture->uuid}", ['text' => ''], $this->headers())->assertUnprocessable();

        $this->deleteJson("/api/v1/captures/{$capture->uuid}", [], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('capture');

        $this->postJson("/api/v1/captures/{$capture->uuid}/archive", [], $this->headers())->assertOk()->assertJsonPath('data.state', 'archived');
        $this->postJson("/api/v1/captures/{$capture->uuid}/restore", [], $this->headers())->assertOk()->assertJsonPath('data.state', 'inbox');
        $this->postJson("/api/v1/captures/{$capture->uuid}/archive", [], $this->headers())->assertOk();
        $this->deleteJson("/api/v1/captures/{$capture->uuid}", [], $this->headers())->assertNoContent();
        $this->assertModelMissing($capture);
    }

    public function test_other_peoples_captures_are_not_found(): void
    {
        $theirs = $this->capture(['text' => 'Private'], $this->viewer);

        $this->getJson("/api/v1/captures/{$theirs->uuid}", $this->headers())->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->patchJson("/api/v1/captures/{$theirs->uuid}", ['text' => 'mine now'], $this->headers())->assertNotFound();
        $this->postJson("/api/v1/captures/{$theirs->uuid}/archive", [], $this->headers())->assertNotFound();
        $this->deleteJson("/api/v1/captures/{$theirs->uuid}", [], $this->headers())->assertNotFound();
        $this->getJson("/api/v1/captures/{$theirs->uuid}/preview?type=issue", $this->headers())->assertNotFound();
        $this->postJson("/api/v1/captures/{$theirs->uuid}/convert", ['type' => 'issue', 'destination_id' => $this->board->id, 'fields' => ['title' => 'x']], $this->headers())->assertNotFound();
        $this->assertSame('Private', $theirs->fresh()->text);

        // malformed ids on writes: 404, not 405 / 500
        $this->patchJson('/api/v1/captures/not-a-uuid', ['text' => 'x'], $this->headers())->assertNotFound();
        $this->postJson('/api/v1/captures/not-a-uuid/convert', [], $this->headers())->assertNotFound();
    }

    public function test_converts_a_capture_like_the_web_dialog(): void
    {
        $capture = $this->capture(['text' => "Fix the login button\nIt is off by 2px", 'url' => 'https://example.com/login']);

        $fields = $this->getJson("/api/v1/captures/{$capture->uuid}/preview?type=issue", $this->headers())
            ->assertOk()->assertJsonPath('data.type', 'issue')->assertJsonPath('data.fields.title', 'Fix the login button')
            ->json('data.fields');
        $this->getJson("/api/v1/captures/{$capture->uuid}/preview?type=nope", $this->headers())->assertUnprocessable();

        $converted = $this->postJson("/api/v1/captures/{$capture->uuid}/convert", ['type' => 'issue', 'destination_id' => $this->board->id, 'fields' => $fields], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.location.type', 'board item')
            ->assertJsonPath('data.capture.state', 'processed')
            ->assertJsonPath('data.capture.target.type', 'issue')
            ->assertJsonPath('data.capture.target.available', true);
        $item = Item::where('title', 'Fix the login button')->firstOrFail();
        $this->assertSame($this->board->id, $item->board_id);
        $this->assertNotNull($converted->json('data.location.url'));

        // a retry creates nothing new
        $this->postJson("/api/v1/captures/{$capture->uuid}/convert", ['type' => 'issue', 'destination_id' => $this->board->id, 'fields' => $fields], $this->headers())
            ->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, Item::where('title', 'Fix the login button')->count());

        // the processed list shows where it went
        $this->getJson('/api/v1/captures?state=processed', $this->headers())->assertOk()
            ->assertJsonPath('data.0.target.title', 'Fix the login button')
            ->assertJsonPath('data.0.target.available', true);

        // the viewer can't create issues on the board: the destination's own policy (403)
        $theirs = $this->capture(['text' => 'Viewer idea'], $this->viewer);
        $this->postJson("/api/v1/captures/{$theirs->uuid}/convert", ['type' => 'issue', 'destination_id' => $this->board->id, 'fields' => ['title' => 'x']], $this->headers($this->viewer))
            ->assertForbidden();
        $this->assertNull($theirs->fresh()->processed_at);

        // into a task list; invalid fields → 422 and the capture stays in the inbox
        $list = TaskList::factory()->create(['project_id' => $this->project->id]);
        $task = $this->capture(['text' => 'Buy milk']);
        $this->postJson("/api/v1/captures/{$task->uuid}/convert", ['type' => 'task', 'destination_id' => $list->id, 'fields' => ['title' => '']], $this->headers())
            ->assertUnprocessable();
        $this->postJson("/api/v1/captures/{$task->uuid}/convert", ['type' => 'task', 'destination_id' => $list->id, 'fields' => ['title' => 'Buy milk', 'due_date' => '2026-11-12']], $this->headers())
            ->assertCreated()->assertJsonPath('data.capture.target.type', 'task');
        $this->assertSame('2026-11-12', TaskListItem::where('title', 'Buy milk')->firstOrFail()->due_date->format('Y-m-d'));
    }

    public function test_lists_the_destinations_the_user_can_write_to(): void
    {
        $list = TaskList::factory()->create(['project_id' => $this->project->id, 'name' => 'Errands']);
        Workspace::factory()->create(); // not the user's

        $response = $this->getJson('/api/v1/capture-destinations', $this->headers())->assertOk();
        $this->assertSame([$this->workspace->slug], array_column($response->json('data'), 'slug'));
        $project = $response->json('data.0.projects.0');
        $this->assertSame($this->project->id, $project['id']);
        $this->assertTrue($project['can_create_pages']);
        $this->assertSame($this->board->id, $project['boards'][0]['id']);
        $this->assertNotEmpty($project['boards'][0]['columns']);
        $this->assertSame([['id' => $list->id, 'name' => 'Errands', 'icon' => null]], $project['task_lists']);

        // a viewer can write nowhere in this project
        $this->assertSame([], $this->getJson('/api/v1/capture-destinations', $this->headers($this->viewer))->assertOk()->json('data'));
    }

    public function test_tokens_of_other_apis_and_unverified_users_are_refused(): void
    {
        $this->getJson('/api/v1/captures')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/captures', $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
        $this->postJson('/api/v1/captures', ['text' => 'x'], $this->appHeaders($this->passportToken($this->editor, ['capture'])))->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->getJson('/api/v1/captures', $this->appHeaders($this->appToken($unverified)))->assertForbidden();

        // and an app token is no extension token
        $this->postJson('/api/ext/v1/captures', ['text' => 'x'], $this->headers())->assertUnauthorized();
    }
}
