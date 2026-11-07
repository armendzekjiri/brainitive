<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApiKeyRequest;
use App\Models\ApiKey;
use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ApiKeys\ApiKeyAreas;
use App\Support\ApiKeys\ApiKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Profile → API keys (Developer API, v1.52.0): personal API keys for the App API /api/v1. Create (the key is shown
 * once, in the response of the create / regenerate request itself — never put in the session), list, regenerate,
 * revoke. Web only: an API key can't manage keys.
 */
class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->page($request);
    }

    public function store(StoreApiKeyRequest $request): SymfonyResponse
    {
        abort_unless(ApiKeys::enabled(), 403, 'API keys are turned off on this server.');
        $validated = $request->validated();

        $created = ApiKeys::create(
            $request->user(),
            trim((string) $validated['name']),
            (string) $validated['scope_type'],
            $request->scopeIds(),
            (array) ($validated['abilities'] ?? []),
            (bool) ($validated['can_delete'] ?? false),
            $request->expiresInDays(),
            $request->ip(),
        );

        return $this->secretPage($request, $created['key'], $created['secret']);
    }

    public function regenerate(Request $request, int $apiKey): SymfonyResponse
    {
        abort_unless(ApiKeys::enabled(), 403, 'API keys are turned off on this server.');
        $key = $this->own($request->user(), $apiKey);
        if (! $key->isActive()) {
            return redirect()->route('profile.api-keys.index')->with('error', 'Only an active key can get a new secret. Create a new key instead.');
        }

        $regenerated = ApiKeys::regenerate($key, $request->ip());

        return $this->secretPage($request, $regenerated['key'], $regenerated['secret']);
    }

    public function destroy(Request $request, int $apiKey): RedirectResponse
    {
        ApiKeys::revoke($this->own($request->user(), $apiKey));

        return redirect()->route('profile.api-keys.index')->with('success', 'API key revoked. It stopped working right away.');
    }

    /** Workspace settings → Developer API: admins allow or block personal API keys for the workspace. */
    public function updateWorkspace(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('update', $workspace);
        $validated = $request->validate(['api_keys_enabled' => ['required', 'boolean']]);

        $workspace->forceFill(['api_keys_enabled' => (bool) $validated['api_keys_enabled']])->save();

        return back()->with('success', $workspace->api_keys_enabled ? 'API keys are allowed in this workspace.' : 'API keys are turned off for this workspace.');
    }

    private function own(User $user, int $id): ApiKey
    {
        $key = ApiKey::query()->where('user_id', $user->id)->whereKey($id)->first();
        abort_unless($key instanceof ApiKey, 404);

        return $key;
    }

    private function page(Request $request, ?ApiKey $created = null, ?string $secret = null): Response
    {
        if ($secret !== null) {
            // the one response carrying the key: encrypted in the browser history (Back can't show it in clear
            // text later; the key is cleared when the session ends) and never cached
            Inertia::encryptHistory();
        }

        $user = $request->user();
        $options = self::options($user);

        $keys = $user->apiKeys()
            // active ones, and the ones that stopped working in the last 30 days (so a revoke is visible)
            ->where(fn ($q) => $q->whereNull('revoked_at')->orWhere('revoked_at', '>', now()->subDays(30)))
            ->orderByRaw('revoked_at is not null')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiKey $key) => self::present($key, $options))
            ->values();

        $page = Inertia::render('Profile/ApiKeys', [
            'apiKeys' => $keys,
            'options' => $options,
            'areas' => collect(ApiKeyAreas::AREAS)->map(fn (array $meta, string $key) => ['key' => $key] + $meta)->values(),
            'expiryDays' => config('api-keys.expiry_days'),
            'enabled' => ApiKeys::enabled(),
            'rateLimits' => config('api-keys.rate_limits'),
            'created' => $created === null ? null : ['key' => self::present($created, $options), 'secret' => $secret],
            'apiBaseUrl' => rtrim((string) config('app.url'), '/').'/api/v1',
        ]);

        return $page;
    }

    /** The response carrying a new key: never stored by a cache. */
    private function secretPage(Request $request, ApiKey $key, string $secret): SymfonyResponse
    {
        $response = $this->page($request, $key, $secret)->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * What a key can point at: the user's workspaces, the projects they can open, the boards of those.
     *
     * @return array{workspaces: list<array<string, mixed>>, projects: list<array<string, mixed>>, boards: list<array<string, mixed>>}
     */
    public static function options(User $user): array
    {
        $workspaces = $user->workspaces()->orderBy('workspaces.name')->get(['workspaces.id', 'workspaces.name', 'workspaces.slug', 'workspaces.api_keys_enabled']);
        $projects = Project::query()->whereIn('id', $user->accessibleProjectIds())->orderBy('name')->orderBy('id')->get(['id', 'name', 'key', 'workspace_id']);
        $boards = Board::query()->whereIn('project_id', $projects->pluck('id'))->orderBy('name')->orderBy('id')->get(['id', 'name', 'project_id']);

        return [
            'workspaces' => $workspaces->map(fn (Workspace $w) => ['id' => $w->id, 'name' => $w->name, 'slug' => $w->slug, 'api_keys_enabled' => (bool) $w->api_keys_enabled])->values()->all(),
            'projects' => $projects->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'key' => $p->key, 'workspace_id' => $p->workspace_id])->values()->all(),
            'boards' => $boards->map(fn (Board $b) => ['id' => $b->id, 'name' => $b->name, 'project_id' => $b->project_id])->values()->all(),
        ];
    }

    /**
     * A key for the page (never its hash). Scope names only for things the user can still open.
     *
     * @param  array{workspaces: list<array<string, mixed>>, projects: list<array<string, mixed>>, boards: list<array<string, mixed>>}  $options
     * @return array<string, mixed>
     */
    private static function present(ApiKey $key, array $options): array
    {
        $pool = match ($key->scope_type) {
            ApiKey::SCOPE_WORKSPACES => $options['workspaces'],
            ApiKey::SCOPE_PROJECTS => $options['projects'],
            ApiKey::SCOPE_BOARDS => $options['boards'],
            default => [],
        };
        $byId = collect($pool)->keyBy('id');
        $ids = $key->scopeIds();

        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scope_type' => $key->scope_type,
            'scope' => collect($ids)->map(fn (int $id) => $byId->has($id) ? ['id' => $id, 'name' => (string) $byId[$id]['name']] : null)->filter()->values(),
            'scope_unavailable' => count(array_filter($ids, fn (int $id) => ! $byId->has($id))),
            'abilities' => ApiKeyAreas::normalize($key->abilities ?? []),
            'can_delete' => (bool) $key->can_delete,
            'status' => $key->revoked_at !== null ? 'revoked' : ($key->isExpired() ? 'expired' : 'active'),
            'created_at' => $key->created_at?->toIso8601String(),
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'last_used_ip' => $key->last_used_ip,
            'expires_at' => $key->expires_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
        ];
    }
}
