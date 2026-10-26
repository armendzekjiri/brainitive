<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshGithubConnection;
use App\Models\Board;
use App\Models\GithubConnection;
use App\Models\GithubInstallation;
use App\Models\GithubRepository;
use App\Models\Item;
use App\Models\ItemDevLink;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Github\GithubApi;
use App\Support\Github\GithubApiException;
use App\Support\Github\GithubAppConfig;
use App\Support\Github\ItemDevelopment;
use App\Support\Github\MergeTransitions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Project settings → Integrations → GitHub. Project admins only (same as other project settings).
 *
 * - GitHub App (BRAI-61): "Connect GitHub" → GitHub's install screen → setup() verifies the
 *   installation belongs to the person (OAuth during install + GET /user/installations) → the
 *   project picks the installation's repositories it follows; refresh; merge transitions per board.
 * - Manual webhooks (legacy): create / rotate the secret / remove, unchanged.
 */
class ProjectGithubController extends Controller
{
    /** Session key: state => [project_id, user_id, at] of connects in progress. */
    private const CONNECT_KEY = 'github_app_connect';

    private const STATE_TTL_MINUTES = 30;

    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $project->githubConnection()->firstOrCreate([], [
            'uuid' => (string) Str::uuid(),
            'secret' => GithubConnection::newSecret(),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'GitHub connection created. Add the webhook to your repository.');
    }

    public function rotate(Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $project->githubConnection()->firstOrFail()->update(['secret' => GithubConnection::newSecret()]);

        return back()->with('success', 'New secret created. Update it in every connected repository’s webhook.');
    }

    public function destroy(Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        // Cascades to deliveries, followed repositories and every linked branch / commit / PR / deployment.
        $project->githubConnection()->delete();

        return back()->with('success', 'GitHub disconnected. Linked development info was removed.');
    }

    /** "Connect GitHub" / "Switch to the GitHub App": off to GitHub's install screen. */
    public function connect(Request $request, Workspace $workspace, Project $project): SymfonyResponse
    {
        $this->authorize('update', $project);

        $app = GithubAppConfig::current();
        if (! $app) {
            return back()->with('error', 'No GitHub App is set up on this instance yet. Ask an instance admin, or use manual webhooks.');
        }
        if (! $app->canVerifyInstallations()) {
            return back()->with('error', 'The GitHub App has no client secret, so installations can’t be verified. Ask an instance admin to recreate it.');
        }

        $state = Str::random(40);
        $pending = collect($request->session()->get(self::CONNECT_KEY, []))
            ->filter(fn ($p) => is_array($p) && ($p['at'] ?? 0) > now()->subMinutes(self::STATE_TTL_MINUTES)->getTimestamp())
            ->take(-4)
            ->put($state, ['project_id' => $project->id, 'user_id' => $request->user()->id, 'at' => now()->getTimestamp()]);
        $request->session()->put(self::CONNECT_KEY, $pending->all());

        if ($app->source === 'fake') {
            // e2e: GitHub's install screen "returns" at once with a fresh installation (FakeGithub)
            $installation = random_int(100000, 999999);

            return Inertia::location(route('github.app.setup', ['installation_id' => $installation, 'setup_action' => 'install', 'code' => 'e2e-'.$installation, 'state' => $state]));
        }

        return Inertia::location($app->installUrl($state));
    }

    /**
     * GitHub's setup callback (install / update / request). The state must be one this session
     * started for this person and a project they still manage; the installation must be one their
     * GitHub account can access — a pasted installation_id of someone else's is refused.
     */
    public function setup(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $pending = $request->session()->get(self::CONNECT_KEY, []);
        $entry = is_array($pending) && $state !== '' ? ($pending[$state] ?? null) : null;
        if (is_array($pending) && $entry) {
            unset($pending[$state]); // one-time
            $request->session()->put(self::CONNECT_KEY, $pending);
        }

        if (! is_array($entry) || (int) ($entry['user_id'] ?? 0) !== $request->user()->id
            || ($entry['at'] ?? 0) < now()->subMinutes(self::STATE_TTL_MINUTES)->getTimestamp()) {
            if ($request->query('setup_action') === 'update' && ($updated = $this->afterRepositoriesChanged($request))) {
                return $updated;
            }

            return redirect()->route('dashboard')->with('error', 'This GitHub link expired or was started elsewhere. Open the project’s settings and connect GitHub again.');
        }

        $project = Project::with('workspace')->find($entry['project_id']);
        abort_unless($project && $project->workspace && Gate::allows('update', $project), 403);
        $back = fn (string $type, string $message) => redirect()->to(route('projects.edit', [$project->workspace->slug, $project->slug]).'?tab=integrations')->with($type, $message);

        if ($request->query('setup_action') === 'request') {
            return $back('success', 'GitHub asked an owner of the account to approve the installation. Connect again once it’s approved.');
        }

        $installationId = (int) $request->query('installation_id', 0);
        $code = (string) $request->query('code', '');
        if ($installationId < 1 || $code === '' || strlen($code) > 200) {
            return $back('error', 'GitHub didn’t confirm the installation. Try connecting again.');
        }

        $api = GithubApi::make();
        if (! $api) {
            return $back('error', 'No GitHub App is set up on this instance.');
        }

        try {
            $userToken = $api->userToken($code);
            if (! in_array($installationId, $api->userInstallationIds($userToken), true)) {
                return $back('error', 'That GitHub installation isn’t available to your GitHub account.');
            }
            // only the repositories THIS person can read: an installation is listed for anyone with
            // access to one of its repositories (e.g. an outside collaborator on an org repository)
            $allowed = $api->userInstallationRepositoryIds($userToken, $installationId);
            $info = $api->installation($installationId);
            $repositories = $api->installationRepositories($installationId);
        } catch (GithubApiException $e) {
            return $back('error', 'GitHub couldn’t be asked about the installation: '.$e->getMessage());
        }

        DB::transaction(function () use ($project, $installationId, $info, $repositories, $allowed, $request) {
            $installation = GithubInstallation::lockForUpdate()->firstOrNew(['installation_id' => $installationId]);
            $installation->fill([
                'account_login' => $info['account_login'] ? Str::limit((string) $info['account_login'], 100, '') : null,
                'account_type' => $info['account_type'],
                'repository_selection' => $info['repository_selection'],
                'repositories' => $repositories,
                'suspended_at' => $info['suspended'] ? ($installation->suspended_at ?? now()) : null,
                'removed_at' => null,
                'refreshed_at' => now(),
            ])->save();

            $connection = $project->githubConnection()->lockForUpdate()->first() ?? $project->githubConnection()->make([
                'uuid' => (string) Str::uuid(),
                'secret' => GithubConnection::newSecret(),
                'created_by' => $request->user()->id,
            ]);
            if ($connection->exists && ! $connection->usesApp()) {
                // switching from manual webhooks: the old hooks stop (their secret no longer matches)
                $connection->secret = GithubConnection::newSecret();
            }
            if ($connection->exists && $connection->github_installation_id && $connection->github_installation_id !== $installation->id) {
                $connection->followedRepositories()->delete(); // they belonged to the other installation
            }
            $connection->forceFill([
                'mode' => GithubConnection::MODE_APP,
                'github_installation_id' => $installation->id,
                'allowed_repository_ids' => $allowed,
                'access_lost_at' => null,
                'last_error' => null,
                'rate_limited_until' => null,
            ])->save();
            // repositories this person can't read are no longer followed
            $connection->followedRepositories()->whereNotIn('repo_id', $allowed === [] ? [0] : $allowed)->delete();
            // repositories the project still follows and the installation still shares are active again
            foreach ($connection->followedRepositories()->get() as $followed) {
                $followed->update(['removed_at' => $installation->repository($followed->repo_id) ? null : ($followed->removed_at ?? now())]);
            }
        });

        return $back('success', 'GitHub connected. Choose the repositories this project follows.');
    }

    /**
     * Back from "Share more repositories on GitHub" (setup_action=update, no state of ours): connect
     * nothing, just re-read the repository list of an installation one of the person's projects
     * already uses (ownership was proven when it was connected) and open that project's settings.
     */
    private function afterRepositoriesChanged(Request $request): ?RedirectResponse
    {
        $installation = GithubInstallation::where('installation_id', (int) $request->query('installation_id', 0))->whereNull('removed_at')->first();
        $connection = $installation
            ? GithubConnection::where('github_installation_id', $installation->id)->where('mode', GithubConnection::MODE_APP)->with('project.workspace')->get()
                ->first(fn (GithubConnection $c) => $c->project && $c->project->workspace && Gate::allows('update', $c->project))
            : null;
        if (! $connection || ! ($api = GithubApi::make())) {
            return null;
        }

        $back = redirect()->to(route('projects.edit', [$connection->project->workspace->slug, $connection->project->slug]).'?tab=integrations');
        $code = (string) $request->query('code', '');
        try {
            $installation->forceFill(['repositories' => $api->installationRepositories($installation->installation_id), 'refreshed_at' => now()])->save();
            // with GitHub's authorization code, the repositories this person can read are offered too
            if ($code !== '' && strlen($code) <= 200) {
                $userToken = $api->userToken($code);
                if (in_array($installation->installation_id, $api->userInstallationIds($userToken), true)) {
                    $allowed = $api->userInstallationRepositoryIds($userToken, $installation->installation_id);
                    $connection->forceFill(['allowed_repository_ids' => array_values(array_unique([...($connection->allowed_repository_ids ?? []), ...$allowed]))])->save();

                    return $back->with('success', 'The repositories shared with the GitHub App were updated. Tick the ones this project follows.');
                }
            }
        } catch (GithubApiException $e) {
            return $back->with('error', 'GitHub couldn’t be asked for the repositories: '.$e->getMessage());
        }

        return $back->with('success', 'The repositories shared with the GitHub App were updated. To follow a newly shared one, use “Connect GitHub” again.');
    }

    /** Which of the installation's repositories this project follows (each repository → one project). */
    public function repositories(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $connection = $project->githubConnection()->with('installation')->first();
        abort_unless($connection && $connection->usesApp() && $connection->installation, 404);
        $installation = $connection->installation;

        $validated = $request->validate([
            'repository_ids' => ['present', 'array', 'max:100'],
            'repository_ids.*' => ['integer', 'min:1'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $validated['repository_ids'])));

        $repos = [];
        foreach ($ids as $id) {
            $repo = $installation->repository($id);
            if (! $repo || ! $connection->mayFollow($id)) {
                return back()->withErrors(['repository_ids' => 'One of the repositories isn’t shared with this GitHub installation. Refresh and try again.']);
            }
            $repos[] = $repo;
        }

        $taken = GithubRepository::whereIn('repo_id', $ids)->where('github_connection_id', '!=', $connection->id)->pluck('full_name');
        if ($taken->isNotEmpty()) {
            return back()->withErrors(['repository_ids' => $taken->implode(', ').' '.($taken->count() === 1 ? 'is' : 'are').' already connected to another project.']);
        }

        try {
            DB::transaction(function () use ($connection, $installation, $ids, $repos) {
                $connection->followedRepositories()->whereNotIn('repo_id', $ids === [] ? [0] : $ids)->delete();
                foreach ($repos as $repo) {
                    GithubRepository::updateOrCreate(
                        ['github_connection_id' => $connection->id, 'repo_id' => $repo['id']],
                        ['github_installation_id' => $installation->id, 'full_name' => $repo['full_name'], 'private' => $repo['private'], 'removed_at' => null],
                    );
                }
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['repository_ids' => 'One of the repositories was just connected to another project.']);
        }

        return back()->with('success', count($ids) === 0 ? 'No repositories followed.' : 'Following '.count($ids).' '.Str::plural('repository', count($ids)).'.');
    }

    /** Per board: when the primary linked PR is merged, move the card to this column (or off). */
    public function transitions(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'transitions' => ['present', 'array', 'max:200'],
            'transitions.*.board_id' => ['required', 'integer'],
            'transitions.*.column_id' => ['nullable', 'integer'],
        ]);

        $boards = $project->boards()->with('columns:id,board_id')->get()->keyBy('id');
        foreach ($validated['transitions'] as $i => $row) {
            $board = $boards->get((int) $row['board_id']);
            if (! $board) {
                return back()->withErrors(["transitions.{$i}.board_id" => 'That board isn’t in this project.']);
            }
            $columnId = ! empty($row['column_id']) ? (int) $row['column_id'] : null;
            if ($columnId && ! $board->columns->contains('id', $columnId)) {
                return back()->withErrors(["transitions.{$i}.column_id" => "That column isn’t on {$board->name}."]);
            }
        }

        foreach ($validated['transitions'] as $row) {
            $board = $boards->get((int) $row['board_id']);
            $settings = $board->settings ?? [];
            $github = (array) ($settings['github'] ?? []);
            if (! empty($row['column_id'])) {
                $github['merge_column_id'] = (int) $row['column_id'];
            } else {
                unset($github['merge_column_id']);
            }
            if ($github === []) {
                unset($settings['github']);
            } else {
                $settings['github'] = $github;
            }
            $board->update(['settings' => $settings]);
        }

        return back()->with('success', 'Merge transitions saved.');
    }

    /** Re-read repositories + open linked PRs from GitHub (queued; backs off on rate limits). */
    public function refresh(Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $connection = $project->githubConnection()->first();
        abort_unless($connection && $connection->usesApp(), 404);
        if ($connection->rate_limited_until?->isFuture()) {
            return back()->with('error', 'GitHub’s rate limit was reached. Refresh again after '.$connection->rate_limited_until->format('H:i').' UTC.');
        }

        RefreshGithubConnection::dispatch($connection->id);

        return back()->with('success', 'Refreshing from GitHub…');
    }

    /** Pick the pull request whose merge moves the card (JSON; the issue's Development section). */
    public function primary(Request $request, Workspace $workspace, Project $project, Board $board, Item $item): JsonResponse
    {
        $this->authorize('update', $item);

        $validated = $request->validate(['link_id' => ['required', 'integer']]);
        $link = ItemDevLink::where('item_id', $item->id)->where('kind', 'pull_request')->find($validated['link_id']);
        abort_unless($link, 422, 'That pull request isn’t linked to this issue.');

        DB::transaction(function () use ($item, $link) {
            ItemDevLink::where('item_id', $item->id)->where('kind', 'pull_request')->where('id', '!=', $link->id)->update(['primary' => false]);
            $link->update(['primary' => true]);
        });

        return response()->json(['development' => ItemDevelopment::for($item)]);
    }

    /**
     * Settings page data; only for people who may manage the project. Never a token or the app's
     * secrets; the legacy webhook secret is shown to project admins as before.
     *
     * @return array<string, mixed>
     */
    public static function settingsFor(Project $project): array
    {
        $app = GithubAppConfig::current();
        $connection = $project->githubConnection()->with('installation')->first();

        return [
            'app' => $app ? [
                'name' => $app->name ?: $app->slug,
                'url' => $app->publicUrl(),
                'can_connect' => $app->canVerifyInstallations(),
            ] : null,
            'connection' => $connection ? self::connectionData($connection, $app) : null,
            'boards' => $project->boards()->with(['columns' => fn ($q) => $q->orderBy('position')])->get()
                ->map(fn (Board $board) => [
                    'id' => $board->id,
                    'name' => $board->name,
                    'merge_column_id' => MergeTransitions::column($board)?->id,
                    'columns' => $board->columns->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'is_done_column' => (bool) $c->is_done_column])->values(),
                ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private static function connectionData(GithubConnection $connection, ?GithubAppConfig $app): array
    {
        $data = [
            'mode' => $connection->mode,
            'last_delivery_at' => $connection->last_delivery_at?->toIso8601String(),
            'last_event' => $connection->last_event,
            'last_error' => $connection->last_error,
            'linked_issues' => $connection->links()->distinct('item_id')->count('item_id'),
        ];

        if (! $connection->usesApp()) {
            return $data + [
                'webhook_url' => $connection->webhookUrl(),
                'secret' => $connection->secret,
                'repositories' => $connection->repositories ?? [],
            ];
        }

        $installation = $connection->installation;
        $followed = $connection->followedRepositories()->get()->keyBy('repo_id');
        $taken = $installation
            ? GithubRepository::where('github_installation_id', $installation->id)->where('github_connection_id', '!=', $connection->id)->pluck('repo_id')->all()
            : [];

        // only repositories the person who connected can read on GitHub (plus what is already followed)
        $available = collect($installation?->repositories ?? [])
            ->filter(fn (array $r) => $connection->mayFollow((int) $r['id']) || $followed->has((int) $r['id']))
            ->map(fn (array $r) => [
                'id' => (int) $r['id'],
                'full_name' => (string) $r['full_name'],
                'private' => (bool) ($r['private'] ?? false),
                'followed' => $followed->has((int) $r['id']),
                'taken' => in_array((int) $r['id'], $taken, true),
                'removed' => $followed->has((int) $r['id']) && $followed->get((int) $r['id'])->removed_at !== null,
            ]);
        // followed repositories the installation no longer shares stay visible, marked removed
        foreach ($followed as $repo) {
            if (! $available->contains('id', $repo->repo_id)) {
                $available->push(['id' => $repo->repo_id, 'full_name' => $repo->full_name, 'private' => $repo->private, 'followed' => true, 'taken' => false, 'removed' => true]);
            }
        }

        return $data + [
            'installation' => $installation ? [
                'account' => $installation->account_login,
                'account_type' => $installation->account_type,
                'suspended' => $installation->suspended_at !== null,
                'removed' => $installation->removed_at !== null,
                'manage_url' => $app ? 'https://github.com/apps/'.rawurlencode($app->slug).'/installations/new' : null,
            ] : null,
            'available_repositories' => $available->sortBy('full_name')->values()->all(),
            'last_refreshed_at' => $connection->last_refreshed_at?->toIso8601String(),
            'rate_limited_until' => $connection->rate_limited_until?->isFuture() ? $connection->rate_limited_until->toIso8601String() : null,
        ];
    }
}
