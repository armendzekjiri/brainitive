<?php

namespace Tests\Feature\AppApi;

use App\Models\AppDevice;
use App\Models\Page;
use App\Models\TaskList;
use App\Models\User;
use App\Support\AppApi\AppClients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Per-client abilities (BRAI-377): a TV signed in with a device code may only use its read-mostly list
 * (`config/app-api.php` → `clients.tv.abilities`); everything else is 403 `client_not_allowed`. Desktop and
 * mobile keep the whole App API.
 */
class AppClientAbilitiesTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    /** The TV's whole scope: the signed-in routes it may call (route names without `app.v1.`). */
    private const TV_ROUTES = [
        'auth.logout',
        'me.show',
        'today',
        'work.index',
        'work.update',
        'my-day.show',
        'my-day.items.update',
        'calendar.events',
        'calendar.events.show',
        'calendar.connections',
        'notifications.index',
        'notifications.unread-count',
        'notifications.read',
        'realtime.show',
        'realtime.token',
    ];

    private AppDevice $tv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        $this->tv = $this->appDevice($this->editor, ['client' => 'tv', 'platform' => 'android_tv', 'name' => 'Living room TV']);
    }

    /** @return array<string, string> */
    private function tv(): array
    {
        return $this->appHeaders($this->appToken($this->editor, $this->tv));
    }

    /** @return array<string, string> */
    private function desktop(): array
    {
        return $this->appHeaders($this->appToken($this->editor));
    }

    private function assertNotAllowed(TestResponse $response, string $call): void
    {
        $this->assertSame(403, $response->status(), "{$call} must be refused for the TV, got {$response->status()}");
        $this->assertSame('client_not_allowed', $response->json('code'), $call);
        $this->assertIsString($response->json('message'));
    }

    public function test_the_tv_scope_is_exactly_the_read_mostly_list(): void
    {
        $signedIn = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with((string) $route->getName(), 'app.v1.') && in_array('auth:app', $route->gatherMiddleware(), true))
            ->map(fn (Route $route) => substr((string) $route->getName(), strlen('app.v1.')));
        $this->assertGreaterThan(100, $signedIn->count());

        $allowed = $signedIn->filter(fn (string $name) => AppClients::allows('tv', $name))->sort()->values()->all();
        $expected = self::TV_ROUTES;
        sort($expected);
        $this->assertSame($expected, $allowed, 'a new route is never open to the TV by accident');

        $this->assertTrue($signedIn->every(fn (string $name) => AppClients::allows('desktop', $name) && AppClients::allows('mobile', $name)));
        $this->assertFalse(AppClients::allows('tv', 'auth.web-session'));
        $this->assertFalse(AppClients::allows('unknown-client', 'me.show'));
        $this->assertFalse(AppClients::allows('desktop', null));
    }

    public function test_the_tv_is_refused_everything_outside_its_list(): void
    {
        $page = Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $list = TaskList::factory()->create(['project_id' => $this->project->id]);
        $item = $this->item(['assigned_to' => $this->editor->id]);
        $other = $this->appDevice($this->editor);
        $slug = $this->workspace->slug;
        $headers = $this->tv();

        $calls = [
            // a webview session: never for a device-code client
            ['POST', '/api/v1/auth/web-session', []],
            // devices + connected apps: no signing other devices out
            ['GET', '/api/v1/devices', []],
            ['PATCH', '/api/v1/devices/current', ['name' => 'Renamed']],
            ['DELETE', "/api/v1/devices/{$other->id}", []],
            ['GET', '/api/v1/me/connected-apps', []],
            ['DELETE', "/api/v1/me/connected-apps/device:{$other->id}", []],
            // invitations, onboarding, inviting others
            ['GET', '/api/v1/invitations/abc123', []],
            ['POST', '/api/v1/invitations/abc123/accept', []],
            ['POST', '/api/v1/workspaces', ['name' => 'Mine']],
            ['POST', "/api/v1/workspaces/{$slug}/invitations", ['email' => 'x@example.com']],
            // data export
            ['GET', '/api/v1/me/data-export', []],
            ['POST', '/api/v1/me/data-export', []],
            // the private calendar feed link
            ['GET', '/api/v1/me/calendar-feed', []],
            ['PUT', '/api/v1/me/calendar-feed', []],
            ['POST', '/api/v1/me/calendar-feed/reset', []],
            ['DELETE', '/api/v1/me/calendar-feed', []],
            // profile changes
            ['PATCH', '/api/v1/me', ['name' => 'Hacked']],
            ['POST', '/api/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('a.png')]],
            ['DELETE', '/api/v1/me/avatar', []],
            ['PUT', '/api/v1/me/notification-preferences', []],
            // uploads
            ['POST', '/api/v1/uploads', ['file' => UploadedFile::fake()->image('b.png')]],
            // reads outside the list
            ['GET', '/api/v1/workspaces', []],
            ['GET', "/api/v1/workspaces/{$slug}", []],
            ['GET', "/api/v1/projects/{$this->project->id}", []],
            ['GET', "/api/v1/items/{$item->id}", []],
            ['GET', "/api/v1/pages/{$page->uuid}", []],
            ['GET', '/api/v1/search?q=x', []],
            ['GET', '/api/v1/captures', []],
            ['GET', '/api/v1/sync/changes', []],
            ['GET', '/api/v1/me/notification-preferences', []],
            // writes outside the list
            ['POST', '/api/v1/me/my-day/items', ['id' => "issue:{$item->id}"]],
            ['DELETE', "/api/v1/me/my-day/items/issue:{$item->id}", []],
            ['POST', '/api/v1/me/my-day/carry-over', []],
            ['POST', '/api/v1/notifications/read-all', []],
            ['POST', '/api/v1/notifications/'.Str::uuid().'/archive', []],
            ['POST', '/api/v1/calendar/events', ['title' => 'x']],
            ['POST', '/api/v1/captures', ['title' => 'x']],
            ['POST', "/api/v1/boards/{$this->board->id}/items", ['title' => 'x']],
            ['PATCH', "/api/v1/items/{$item->id}", ['title' => 'x']],
            ['DELETE', "/api/v1/items/{$item->id}", []],
            ['POST', "/api/v1/items/{$item->id}/comments", ['body' => 'x']],
            ['POST', "/api/v1/task-lists/{$list->id}/items", ['title' => 'x']],
            ['POST', "/api/v1/pages/{$page->uuid}/collab-token", []],
            ['POST', '/api/v1/sync', ['changes' => []]],
            ['POST', '/api/v1/me/timer/start', []],
            // and an id that doesn't exist: still 403, never a 404 that says whether it does
            ['GET', '/api/v1/items/999999', []],
        ];

        foreach ($calls as [$method, $uri, $data]) {
            $this->assertNotAllowed($this->json($method, $uri, $data, $headers), "{$method} {$uri}");
        }

        // nothing changed behind the 403s
        $this->assertNull($other->fresh()->revoked_at);
        $this->assertSame($item->title, $item->fresh()->title);
        $this->assertNotSame('Hacked', $this->editor->fresh()->name);
    }

    public function test_the_tv_list_works(): void
    {
        $item = $this->item(['assigned_to' => $this->editor->id]);
        $headers = $this->tv();
        $notification = $this->editor->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\Test', 'data' => ['type' => 'mentioned']]);
        config(['collab.url' => 'wss://brainitive.test/collab', 'collab.secret' => 'test-collab-secret', 'collab.e2e_opt_in' => false, 'live.enabled' => true, 'live.url' => null]);

        $this->getJson('/api/v1/instance')->assertOk();
        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('data.device.client', 'tv');
        $this->getJson('/api/v1/me/today', $headers)->assertOk();
        $this->getJson('/api/v1/me/work', $headers)->assertOk();
        $this->patchJson("/api/v1/me/work/issue:{$item->id}", ['done' => true], $headers)->assertOk()->assertJsonPath('data.done', true);
        $this->getJson('/api/v1/me/my-day', $headers)->assertOk();
        // My Day done / undone: allowed (an item that isn't planned today → 404 from the controller, not 403)
        $this->assertNotSame(403, $this->patchJson("/api/v1/me/my-day/items/issue:{$item->id}", ['done' => false], $headers)->status());
        $this->getJson('/api/v1/calendar/events', $headers)->assertOk();
        $this->getJson('/api/v1/calendar/connections', $headers)->assertOk();
        $this->getJson('/api/v1/notifications', $headers)->assertOk();
        $this->getJson('/api/v1/notifications/unread-count', $headers)->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->postJson("/api/v1/notifications/{$notification->id}/read", [], $headers)->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->getJson('/api/v1/realtime', $headers)->assertOk()->assertJsonPath('data.enabled', true);
        $this->postJson('/api/v1/realtime/token', ['channels' => ["user.{$this->editor->id}"]], $headers)->assertOk()
            ->assertJsonPath('data.channels', ["user.{$this->editor->id}"]);
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertNoContent();
        $this->assertNotNull($this->tv->fresh()->revoked_at);
    }

    public function test_desktop_and_mobile_keep_the_whole_api(): void
    {
        $headers = $this->desktop();
        $mobile = $this->appHeaders($this->appToken($this->editor, $this->appDevice($this->editor, ['client' => 'mobile', 'platform' => 'ios'])));

        foreach ([$headers, $mobile] as $client) {
            $this->getJson('/api/v1/devices', $client)->assertOk();
            $this->getJson('/api/v1/me/connected-apps', $client)->assertOk();
            $this->getJson('/api/v1/workspaces', $client)->assertOk();
            $this->getJson('/api/v1/me/calendar-feed', $client)->assertOk();
            $this->patchJson('/api/v1/me', ['name' => 'Still me'], $client)->assertOk();
        }
        $this->postJson('/api/v1/auth/web-session', [], $headers)->assertOk();
    }

    public function test_an_unknown_client_may_call_nothing(): void
    {
        $gone = $this->appDevice($this->editor, ['client' => 'watch', 'platform' => 'macos']);

        $this->assertNotAllowed($this->getJson('/api/v1/me', $this->appHeaders($this->appToken($this->editor, $gone))), 'GET /me');
    }

    public function test_tokens_of_other_users_and_apis_still_get_401(): void
    {
        $this->getJson('/api/v1/devices')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/devices', $this->appHeaders($this->passportToken(User::factory()->create())))->assertUnauthorized();
    }
}
