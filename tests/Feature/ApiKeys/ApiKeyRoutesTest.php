<?php

namespace Tests\Feature\ApiKeys;

use App\Support\ApiKeys\ApiKeyAreas;
use App\Support\ApiKeys\ApiKeyRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\ApiKeys\Concerns\UsesApiKeys;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Developer API (v1.52.0): every /api/v1 route declares what a personal API key needs (ApiKeyRoutes) — a new
 * endpoint without a declaration fails here (and is refused for keys anyway) — and the excluded ones (account,
 * devices, sessions, connected apps, data export, notifications, realtime, live co-editing, sync) answer 403
 * `key_endpoint_not_allowed` even to a key with every area.
 */
class ApiKeyRoutesTest extends TestCase
{
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesApiKeys;

    /** @return list<RoutingRoute> */
    private function v1Routes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => Str::startsWith($route->uri(), 'api/v1/') || $route->uri() === 'api/v1')
            ->values()->all();
    }

    public function test_every_api_v1_route_is_declared_for_api_keys(): void
    {
        $undeclared = [];
        foreach ($this->v1Routes() as $route) {
            $name = Str::after((string) $route->getName(), 'app.v1.');
            if (ApiKeyRoutes::entry($name) === null) {
                $undeclared[] = $name.' ('.implode('|', $route->methods()).' '.$route->uri().')';
            }
        }

        $this->assertSame([], $undeclared, 'Declare these routes in App\Support\ApiKeys\ApiKeyRoutes::MAP (an area, null, or false = never with an API key).');

        // and no stale entries
        $names = collect($this->v1Routes())->map(fn (RoutingRoute $r) => Str::after((string) $r->getName(), 'app.v1.'))->all();
        $this->assertSame([], array_values(array_diff(array_keys(ApiKeyRoutes::MAP), $names)), 'ApiKeyRoutes::MAP lists routes that no longer exist.');
    }

    public function test_declared_areas_and_options_are_valid(): void
    {
        foreach (ApiKeyRoutes::MAP as $name => $raw) {
            $entry = ApiKeyRoutes::entry($name);
            foreach (array_filter(explode('|', (string) $entry['area'])) as $area) {
                $this->assertContains($area, ApiKeyAreas::keys(), "{$name}: unknown area {$area}");
            }
            $this->assertContains($entry['level'], [null, 'read', 'write'], "{$name}: level");
            $this->assertContains($entry['scope'], [null, 'workspace', 'project', 'boardless'], "{$name}: scope");
        }
    }

    public function test_excluded_endpoints_are_refused_even_with_every_area(): void
    {
        $this->setUpBoard();
        $secret = $this->apiKey($this->editor, $this->everything(), canDelete: true);

        $checked = 0;
        foreach ($this->v1Routes() as $route) {
            $name = Str::after((string) $route->getName(), 'app.v1.');
            if (ApiKeyRoutes::MAP[$name] !== false) {
                continue;
            }
            $uri = '/'.preg_replace_callback('/\{([^}]+)\}/', fn ($m) => match (true) {
                str_contains($m[1], 'workspace') => $this->workspace->slug,
                $m[1] === 'uuid' => (string) Str::uuid(),
                default => '1',
            }, $route->uri());
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();

            $this->json($method, $uri, [], $this->keyHeaders($secret))
                ->assertForbidden()
                ->assertJsonPath('code', 'key_endpoint_not_allowed');
            $checked++;
        }

        $this->assertGreaterThan(40, $checked);
    }
}
