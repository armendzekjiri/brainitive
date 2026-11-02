<?php

namespace Tests\Feature\Calendar;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\UpdateGoogleTaskTool;
use App\Models\CalendarConnection;
use App\Services\GoogleTasksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Codex review, finding 14: a Google task id is one opaque path segment of the selected list. `../../other-list/…`
 * must not climb into another list of the same Google account (Google is never called for real: Http::fake).
 */
class GoogleTasksIdsTest extends TestCase
{
    use RefreshDatabase;

    private CalendarConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = CalendarConnection::factory()->tasks()->create([
            'external_calendar_id' => 'allowed-list',
            'token_expires_at' => now()->addHour(),
        ]);
        Http::fake(['tasks.googleapis.com/*' => Http::response(['id' => 'x', 'title' => 'T', 'status' => 'needsAction'])]);
    }

    public function test_the_mcp_tool_refuses_a_task_id_that_leaves_the_list(): void
    {
        foreach (['../../other-list/tasks/foreign-task', 'a/b', 'a\\b', '..', '.', "a\nb", str_repeat('a', 257)] as $id) {
            BrainitiveServer::actingAs($this->connection->user)->tool(UpdateGoogleTaskTool::class, [
                'connection_id' => $this->connection->id, 'task_id' => $id, 'title' => 'Hijacked',
            ])->assertHasErrors();
        }

        Http::assertNothingSent();
    }

    public function test_the_service_keeps_every_task_id_inside_the_list(): void
    {
        $service = app(GoogleTasksService::class);
        foreach (['../../other-list/tasks/foreign-task', '..', '.'] as $id) {
            $this->assertNull($service->getTask($this->connection, $id));
            $this->assertNull($service->updateTask($this->connection, $id, ['title' => 'x']));
            $this->assertFalse($service->deleteTask($this->connection, $id));
        }
        Http::assertNothingSent();

        // an ordinary id with characters that need encoding stays one segment
        $service->updateTask($this->connection, 'abc def?x=1', ['title' => 'Renamed']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://tasks.googleapis.com/tasks/v1/lists/allowed-list/tasks/abc%20def%3Fx%3D1');
    }

    public function test_a_normal_update_goes_to_the_selected_list(): void
    {
        BrainitiveServer::actingAs($this->connection->user)->tool(UpdateGoogleTaskTool::class, [
            'connection_id' => $this->connection->id, 'task_id' => 'MTIzNDU2Nzg5_-', 'title' => 'Renamed',
        ])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->url() === 'https://tasks.googleapis.com/tasks/v1/lists/allowed-list/tasks/MTIzNDU2Nzg5_-');
    }
}
