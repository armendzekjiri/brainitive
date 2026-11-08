<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\Workspace;
use App\Support\ApiKeys\ApiKeyAccess;
use App\Support\ApiKeys\ApiKeyAreas;
use App\Support\ApiKeys\ApiKeyLocation;
use App\Support\ApiKeys\ApiKeyLocator;
use App\Support\ApiKeys\ApiKeyRoutes;
use App\Support\ApiKeys\ApiKeys;
use App\Support\AppApi\AppApiErrors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a personal API key may call (Developer API, v1.52.0). Runs after `auth:app` and before route-model
 * binding; device tokens (desktop / mobile / TV) pass untouched. For a key:
 *  1. the route must be declared in ApiKeyRoutes and not excluded → else 403 `key_endpoint_not_allowed`;
 *  2. the key's area level covers the method (GET / HEAD read, POST / PUT / PATCH write, DELETE write + the delete
 *     flag) → else 403 `key_ability_denied`;
 *  3. the route's resource (first known parameter, or the declared one) is in the key's scope → else 403
 *     `key_scope_denied` naming the place — only when the user can open it; otherwise the same 404 the
 *     controller gives, so a key never reveals what the user can't see — or 403 `workspace_api_keys_disabled`.
 * Ids in bodies and every policy check are covered by ApiKeyGate; lists by the key-aware access helpers.
 */
class AuthorizeApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ApiKeys::looksLike($request->bearerToken())) {
            return $next($request);
        }

        // the guard put the key on the request; a guard that already knew the user (tests) didn't
        $access = ApiKeyAccess::current();
        if ($access === null) {
            ApiKeys::resolve($request, $request->bearerToken());
            $access = ApiKeyAccess::current();
        }
        if ($access === null) {
            return AppApiErrors::json(401, 'Unauthenticated.');
        }

        $route = $request->route();
        $name = $route?->getName();
        $name = is_string($name) && str_starts_with($name, 'app.v1.') ? substr($name, strlen('app.v1.')) : null;
        $entry = ApiKeyRoutes::entry($name);

        if ($entry === null || ! $entry['allowed']) {
            return AppApiErrors::json(403, 'API keys can’t use this endpoint. It is only available in the Brainitive apps and on the web.', code: AppApiErrors::KEY_ENDPOINT_NOT_ALLOWED);
        }
        if ($entry['public']) {
            return $next($request);
        }

        if (($denied = $this->ability($request, $access, $entry)) !== null) {
            return $denied;
        }

        if (($denied = $this->scope($request, $access, $entry)) !== null) {
            return $denied;
        }

        try {
            return $next($request);
        } finally {
            // the key's query scopes end with the controller: deferred automations run as the system
            ApiKeyAccess::end();
        }
    }

    /** @param  array{area: ?string, level: ?string, areas: ?string}  $entry */
    private function ability(Request $request, ApiKeyAccess $access, array $entry): ?Response
    {
        $method = strtoupper($request->method());
        $level = $entry['level'] ?? (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) ? ApiKeyAreas::READ : ApiKeyAreas::WRITE);

        if (($required = ApiKeyRoutes::requiredAreas($entry['areas'])) !== null) {
            foreach ($required as $area) {
                if (! $access->can($area, ApiKeyAreas::READ)) {
                    return AppApiErrors::json(403, 'This key can’t use this endpoint: it needs read access to '.(count($required) > 2 ? 'every area' : implode(' and ', array_map(fn ($a) => mb_strtolower(ApiKeyAreas::label($a)), $required))).' ('.ApiKeyAreas::label($area).' is missing).', code: AppApiErrors::KEY_ABILITY_DENIED);
                }
            }
        } elseif ($entry['area'] !== null) {
            $areas = explode('|', $entry['area']);
            $granted = array_filter($areas, fn (string $area) => $access->can($area, $level));
            if ($granted === []) {
                $labels = implode(' or ', array_map(fn (string $a) => mb_strtolower(ApiKeyAreas::label($a)), $areas));

                return AppApiErrors::json(403, 'This key can’t '.ApiKeyAreas::verb($level).' '.$labels.'.', code: AppApiErrors::KEY_ABILITY_DENIED);
            }
        } elseif ($level !== ApiKeyAreas::READ) {
            // no area: read only (GET /me, workspaces, projects)
            return AppApiErrors::json(403, 'API keys can only read this.', code: AppApiErrors::KEY_ABILITY_DENIED);
        }

        if ($method === 'DELETE' && $entry['level'] === null && ! $access->canDelete()) {
            return AppApiErrors::json(403, 'This key can’t delete. Turn on “Delete” for the key to allow it.', code: AppApiErrors::KEY_ABILITY_DENIED);
        }

        return null;
    }

    /** @param  array{scope: ?string, param: ?string}  $entry */
    private function scope(Request $request, ApiKeyAccess $access, array $entry): ?Response
    {
        if ($entry['scope'] === 'boardless' && $access->isLimitedToBoards()) {
            return AppApiErrors::json(403, 'Keys limited to boards can’t use this endpoint.', code: AppApiErrors::KEY_SCOPE_DENIED);
        }

        $location = $this->locate($request, $entry['param']);
        if ($location === null) {
            // nothing to check here (personal data, a list of the user's own, or an id that doesn't exist: the
            // controller answers its 404); a workspace-wide route needs a key over whole workspaces anyway
            return $entry['scope'] === 'workspace' && ! $access->coversWholeWorkspaces() && $this->hasParam($request, $entry['param'])
                ? AppApiErrors::json(403, 'This key covers only some projects or boards, not whole workspaces.', code: AppApiErrors::KEY_SCOPE_DENIED)
                : null;
        }

        $wide = match ($entry['scope']) {
            'workspace' => new ApiKeyLocation($location->workspaceId, workspaceWide: true),
            default => $location,
        };
        $allowed = match ($entry['scope']) {
            'project' => $location->projectId !== null
                ? $access->allowsWholeProject($location->projectId, $location->workspaceId)
                : $access->allows($location),
            default => $access->allows($wide),
        };
        if ($allowed) {
            return null;
        }

        // never name (or confirm) a place the user can't open: the controller's own 404 then
        if (! $this->userCanOpen($access, $location)) {
            return AppApiErrors::json(404, 'Not found.');
        }
        if ($access->workspaceDisabled($location->workspaceId)) {
            return AppApiErrors::json(403, 'The admins of this workspace turned off API keys.', code: AppApiErrors::WORKSPACE_API_KEYS_DISABLED);
        }

        // name the widest place that is out of scope: the workspace, else the project, else the board
        $place = match (true) {
            ! $access->allowsWorkspace($location->workspaceId) => ApiKeyLocator::describe(new ApiKeyLocation($location->workspaceId)),
            $entry['scope'] === 'workspace' => 'all of '.ApiKeyLocator::describe(new ApiKeyLocation($location->workspaceId)),
            $location->projectId !== null && ! $access->allowsProject($location->projectId, $location->workspaceId) => ApiKeyLocator::describe(new ApiKeyLocation($location->workspaceId, $location->projectId)),
            $entry['scope'] === 'project' && $location->projectId !== null => 'all of '.ApiKeyLocator::describe(new ApiKeyLocation($location->workspaceId, $location->projectId)),
            $location->workspaceWide => 'all of '.ApiKeyLocator::describe(new ApiKeyLocation($location->workspaceId)),
            default => ApiKeyLocator::describe($location),
        };

        return AppApiErrors::json(403, 'This key has no access to '.$place.'.', code: AppApiErrors::KEY_SCOPE_DENIED);
    }

    private function locate(Request $request, ?string $declared): ?ApiKeyLocation
    {
        $route = $request->route();
        if ($declared !== null) {
            [$param, $type] = explode(':', $declared, 2);
            $value = $route?->parameter($param) ?? $request->query($param) ?? $request->input($param);

            return $value === null ? null : ApiKeyLocator::ofParam($type, $value);
        }

        foreach ($route?->parameterNames() ?? [] as $param) {
            if (! isset(ApiKeyRoutes::PARAMS[$param])) {
                continue;
            }
            $value = $route->parameter($param);
            $extra = $param === 'scopeId' ? (string) $route->parameter('scopeType') : null;

            return ApiKeyLocator::ofParam(ApiKeyRoutes::PARAMS[$param], $value, $extra);
        }

        return null;
    }

    private function hasParam(Request $request, ?string $declared): bool
    {
        $route = $request->route();
        if ($declared !== null) {
            return true;
        }

        return array_intersect($route?->parameterNames() ?? [], array_keys(ApiKeyRoutes::PARAMS)) !== [];
    }

    /** As the user, without the key: may they open the place at all? (Policies; never a key's view.) */
    private function userCanOpen(ApiKeyAccess $access, ApiKeyLocation $location): bool
    {
        $user = $access->user;
        $request = request();
        $saved = $request->attributes->get(ApiKeyAccess::ATTRIBUTE);
        $request->attributes->remove(ApiKeyAccess::ATTRIBUTE);
        try {
            if ($location->projectId !== null) {
                $project = Project::with('workspace')->find($location->projectId);

                return $project instanceof Project && $project->workspace !== null && $user->hasProjectAccess($project);
            }
            $workspace = Workspace::find($location->workspaceId);

            return $workspace instanceof Workspace && $user->hasWorkspaceAccess($workspace);
        } finally {
            $request->attributes->set(ApiKeyAccess::ATTRIBUTE, $saved);
        }
    }
}
