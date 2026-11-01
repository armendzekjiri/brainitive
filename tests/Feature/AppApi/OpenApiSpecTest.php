<?php

namespace Tests\Feature\AppApi;

use App\Http\Middleware\EnsureUserIsNotSuspended;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Infer\Context;
use Dedoc\Scramble\Infer\Reflector\ClassReflector;
use Dedoc\Scramble\Infer\Reflector\MethodReflector;
use Dedoc\Scramble\Infer\Reflector\PropertyReflector;
use Dedoc\Scramble\Infer\Services\FileNameResolver;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Helpers\JsonResourceHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\AppApi\Fixtures\OpenApiProbeController;
use Tests\TestCase;

/**
 * The committed OpenAPI spec of the App API v1 (BRAI-381) is the contract the desktop and mobile
 * apps are generated from. This is the drift check CI runs (PHPUnit job): the committed file must
 * be exactly what `scramble:export` produces now, and it documents /api/v1 and nothing else.
 *
 * Scramble reads model column types from the database, so the spec is only reproducible against a
 * fully migrated schema: the test database (RefreshDatabase), never your dev database. Hence the
 * regenerate command runs this test in write mode (`composer openapi`).
 */
class OpenApiSpecTest extends TestCase
{
    use RefreshDatabase;

    private const SPEC = 'docs/api/openapi.json';

    /**
     * Scramble keeps inference state in statics (the extension broker of the first app instance,
     * reflection caches). A test that rendered the docs earlier in the same PHPUnit process
     * (ApiDocsAccessTest) would otherwise change what this export infers, e.g. a cursor list's
     * description: start every test from a clean slate so the file is the same in any test order.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Context::reset();
        foreach ([ClassReflector::class, MethodReflector::class, PropertyReflector::class] as $reflector) {
            (fn () => static::$cache = [])->bindTo(null, $reflector)();
        }
        JsonResourceHelper::$jsonResourcesModelTypesCache = [];
        FileNameResolver::$nameContextCache = [];
    }

    private const REGENERATE = 'composer openapi (= OPENAPI_WRITE=1 php artisan test --filter=OpenApiSpecTest; in a worktree add TEST_DB_SUFFIX=<name>)';

    public function test_the_committed_spec_is_up_to_date(): void
    {
        $committed = base_path(self::SPEC);
        $fresh = tempnam(sys_get_temp_dir(), 'openapi-');

        try {
            $this->artisan('scramble:export', ['--path' => $fresh])->assertSuccessful();

            if (getenv('OPENAPI_WRITE')) {
                copy($fresh, $committed);
            }

            $this->assertFileExists($committed, 'docs/api/openapi.json is missing. Run `'.self::REGENERATE.'` and commit it.');
            $this->assertSame(
                file_get_contents($fresh),
                file_get_contents($committed),
                'docs/api/openapi.json is out of date with routes/app-api.php, the Form Requests or the Resources. '
                .'Run `'.self::REGENERATE.'` and commit the result.',
            );
        } finally {
            @unlink($fresh);
        }
    }

    public function test_only_api_v1_is_documented_and_every_api_v1_route_is(): void
    {
        $spec = $this->generate();

        $documented = collect($spec['paths'] ?? [])
            ->flatMap(fn (array $operations, string $path) => collect(array_keys($operations))
                ->map(fn (string $method) => strtoupper($method).' '.$path))
            ->sort()->values()->all();

        // The server URL carries /api/v1, so the paths are relative to it.
        $expected = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => $route->uri() === 'api/v1' || Str::startsWith($route->uri(), 'api/v1/'))
            ->map(fn (RoutingRoute $route) => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first()
                .' /'.Str::after($route->uri(), 'api/v1/'))
            ->sort()->values()->all();

        $this->assertSame($expected, $documented, 'The spec must document exactly the /api/v1 routes.');

        foreach (array_keys($spec['paths'] ?? []) as $path) {
            $this->assertDoesNotMatchRegularExpression('#^/(api/|mcp|oauth|webhooks|docs)#', $path, "Non-v1 path leaked into the spec: {$path}");
        }

        $this->assertSame(['https://{instance}/api/v1'], array_column($spec['servers'], 'url'));
    }

    public function test_every_operation_is_tagged_with_its_registry_module(): void
    {
        // the modules of docs/ai/API.md, in order: #[Group('<Module>', weight: n)] on each AppApi controller
        $modules = ['Instance', 'Auth', 'Account', 'Devices', 'Invitations', 'Workspaces', 'Projects', 'Members', 'Navigation', 'Search', 'Notifications', 'Connected apps', 'My Work', 'Calendar', 'Task lists', 'Captures', 'Snippets', 'Bookmarks', 'Time tracking', 'Boards', 'Issues'];

        foreach ($this->generate()['paths'] ?? [] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $this->assertCount(1, $operation['tags'] ?? [], strtoupper($method)." {$path}: exactly one tag.");
                $this->assertContains($operation['tags'][0], $modules, strtoupper($method)." {$path} has tag `{$operation['tags'][0]}`: "
                    ."put #[Group('<Module>', weight: n)] on its controller (a new module also gets a section in docs/ai/API.md and an entry here).");
            }
        }
    }

    public function test_the_spec_carries_the_api_version_and_bearer_auth(): void
    {
        $spec = $this->generate();

        // the API's own version, not the app release (HelpModal CHANGELOG), so a release alone never changes the spec
        $this->assertSame('1.0.0', $spec['info']['version']);
        $this->assertSame('Brainitive App API', $spec['info']['title']);
        $this->assertStringContainsString('docs/ai/API.md', $spec['info']['description']);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['appToken']['scheme']);
        $this->assertSame([['appToken' => []]], $spec['security']);
    }

    public function test_errors_are_documented_in_the_app_api_shape_and_public_routes_need_no_token(): void
    {
        Route::middleware('app-api')->prefix('api/v1')->name('app.v1.')->group(function () {
            Route::get('_openapi-probe', [OpenApiProbeController::class, 'publicInfo'])->name('probe.public');
            Route::middleware(['auth:app', EnsureUserIsNotSuspended::class])
                ->post('_openapi-probe', [OpenApiProbeController::class, 'store'])->name('probe.store');
        });

        $spec = $this->generate();
        $public = $spec['paths']['/_openapi-probe']['get'];
        $authed = $spec['paths']['/_openapi-probe']['post'];

        $this->assertSame([], $public['security'], 'A route without auth:app is public.');
        $this->assertArrayNotHasKey('security', $authed, 'An auth:app route uses the global bearer scheme.');

        foreach ([401 => 'unauthenticated', 403 => 'forbidden', 422 => 'validation_failed', 429 => 'rate_limited'] as $status => $code) {
            $this->assertArrayHasKey($status, $authed['responses'], "POST is missing its {$status} response.");
            $body = $this->resolve($spec, $authed['responses'][$status])['content']['application/json']['schema'];

            $this->assertSame([$code], $body['properties']['code']['enum'], "{$status} must document code `{$code}`.");
            $this->assertContains('message', $body['required']);
            $this->assertContains('code', $body['required']);
            $status === 422
                ? $this->assertArrayHasKey('errors', $body['properties'])
                : $this->assertArrayNotHasKey('errors', $body['properties']);
        }

        $this->assertArrayHasKey('429', $public['responses'], 'Every /api/v1 route is throttled.');
    }

    /** @return array<string, mixed> */
    private function generate(): array
    {
        return app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function resolve(array $spec, array $response): array
    {
        if (! isset($response['$ref'])) {
            return $response;
        }

        return $spec['components']['responses'][Str::afterLast($response['$ref'], '/')];
    }
}
