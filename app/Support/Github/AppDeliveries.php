<?php

namespace App\Support\Github;

use App\Models\GithubAppDelivery;
use App\Models\GithubConnection;
use App\Models\GithubInstallation;
use App\Models\GithubRepository;
use Illuminate\Support\Facades\DB;

/**
 * Processes one delivery to the GitHub App's webhook (BRAI-61):
 * - `installation` (deleted / suspend / unsuspend / created) and `installation_repositories`
 *   (added / removed) keep github_installations + github_repositories current; losing access marks
 *   the affected connections and notifies their admins once (ConnectionAccess);
 * - every other event is routed by installation id + repository id to the ONE project that follows
 *   that repository, and handed to DevelopmentSync — exactly what a manual webhook does.
 * Deliveries for repositories nobody follows (or another installation's) are "ignored".
 */
final class AppDeliveries
{
    public const EVENTS = [...DevelopmentSync::EVENTS, 'installation', 'installation_repositories'];

    public static function process(GithubAppDelivery $delivery): void
    {
        $payload = json_decode($delivery->payload, true);
        if (! is_array($payload)) {
            $delivery->update(['status' => 'failed', 'error' => 'Payload is not JSON.']);

            return;
        }

        match ($delivery->event) {
            'installation' => self::installation($delivery, $payload),
            'installation_repositories' => self::installationRepositories($delivery, $payload),
            default => self::route($delivery, $payload),
        };
    }

    private static function installation(GithubAppDelivery $delivery, array $payload): void
    {
        $installationId = (int) data_get($payload, 'installation.id');
        $action = (string) data_get($payload, 'action');
        if ($installationId < 1) {
            self::done($delivery, 'ignored', 'No installation in the payload.');

            return;
        }

        DB::transaction(function () use ($delivery, $payload, $installationId, $action) {
            $installation = GithubInstallation::lockForUpdate()->firstOrNew(['installation_id' => $installationId]);
            $installation->fill([
                'account_login' => mb_substr((string) data_get($payload, 'installation.account.login', $installation->account_login), 0, 100) ?: null,
                'account_type' => data_get($payload, 'installation.account.type', $installation->account_type),
                'repository_selection' => data_get($payload, 'installation.repository_selection', $installation->repository_selection),
            ]);
            $account = $installation->account_login ?: 'the GitHub account';

            switch ($action) {
                case 'deleted':
                    $installation->removed_at = now();
                    $installation->save();
                    $installation->followed()->whereNull('removed_at')->update(['removed_at' => now()]);
                    foreach (self::connections($installation) as $connection) {
                        ConnectionAccess::lost($connection, "The GitHub App was uninstalled from {$account}. Connect GitHub again to resume syncing.");
                    }
                    break;
                case 'suspend':
                    $installation->suspended_at = now();
                    $installation->save();
                    foreach (self::connections($installation) as $connection) {
                        ConnectionAccess::lost($connection, "The GitHub App was suspended on {$account}. Unsuspend it in GitHub to resume syncing.");
                    }
                    break;
                case 'unsuspend':
                    $installation->suspended_at = null;
                    $installation->save();
                    foreach (self::connections($installation) as $connection) {
                        if (! $connection->followedRepositories()->whereNotNull('removed_at')->exists()) {
                            ConnectionAccess::restored($connection);
                        }
                    }
                    break;
                default: // created, new_permissions_accepted
                    if ($action === 'created') {
                        $installation->removed_at = null;
                        $installation->repositories = array_map([GithubApi::class, 'repo'], (array) data_get($payload, 'repositories', []));
                    }
                    $installation->save();
            }

            self::done($delivery, 'processed');
        });
    }

    private static function installationRepositories(GithubAppDelivery $delivery, array $payload): void
    {
        $installationId = (int) data_get($payload, 'installation.id');
        if ($installationId < 1) {
            self::done($delivery, 'ignored', 'No installation in the payload.');

            return;
        }

        DB::transaction(function () use ($delivery, $payload, $installationId) {
            $installation = GithubInstallation::lockForUpdate()->firstOrNew(['installation_id' => $installationId]);
            $installation->account_login ??= mb_substr((string) data_get($payload, 'installation.account.login', ''), 0, 100) ?: null;
            $installation->repository_selection = data_get($payload, 'repository_selection', $installation->repository_selection);

            $added = array_map([GithubApi::class, 'repo'], (array) data_get($payload, 'repositories_added', []));
            $removedIds = array_map(fn ($r) => (int) data_get($r, 'id'), (array) data_get($payload, 'repositories_removed', []));

            $repos = collect($installation->repositories ?? [])
                ->reject(fn ($r) => in_array((int) $r['id'], $removedIds, true) || in_array((int) $r['id'], array_column($added, 'id'), true))
                ->merge($added)
                ->sortBy('full_name')->values()->all();
            $installation->repositories = $repos;
            $installation->save();

            if ($installation->exists && $removedIds !== []) {
                $lost = GithubRepository::where('github_installation_id', $installation->id)
                    ->whereIn('repo_id', $removedIds)->whereNull('removed_at')->get();
                foreach ($lost->groupBy('github_connection_id') as $rows) {
                    GithubRepository::whereKey($rows->modelKeys())->update(['removed_at' => now()]);
                    $connection = GithubConnection::find($rows->first()->github_connection_id);
                    if ($connection) {
                        $names = $rows->pluck('full_name')->implode(', ');
                        ConnectionAccess::lost($connection, "{$names} ".($rows->count() === 1 ? 'was' : 'were').' removed from the GitHub App installation, so its events no longer arrive. Add it again in GitHub to resume.');
                    }
                }
            }

            if ($added !== []) {
                $back = GithubRepository::where('github_installation_id', $installation->id)
                    ->whereIn('repo_id', array_column($added, 'id'))->whereNotNull('removed_at')->get();
                foreach ($back as $row) {
                    $row->update(['removed_at' => null]);
                }
                foreach ($back->pluck('github_connection_id')->unique() as $connectionId) {
                    $connection = GithubConnection::find($connectionId);
                    if ($connection && $installation->isActive() && ! $connection->followedRepositories()->whereNotNull('removed_at')->exists()) {
                        ConnectionAccess::restored($connection);
                    }
                }
            }

            self::done($delivery, 'processed');
        });
    }

    private static function route(GithubAppDelivery $delivery, array $payload): void
    {
        $installationId = (int) data_get($payload, 'installation.id');
        $repoId = (int) data_get($payload, 'repository.id');
        if ($installationId < 1 || $repoId < 1) {
            self::done($delivery, 'ignored', 'Not about a repository.');

            return;
        }

        // The repository must be followed by a project THROUGH this installation (a delivery for
        // another installation's repository with the same id never reaches the project).
        $followed = GithubRepository::where('repo_id', $repoId)
            ->whereNull('removed_at')
            ->whereHas('installation', fn ($q) => $q->where('installation_id', $installationId)->whereNull('removed_at'))
            ->with('connection.project')
            ->first();
        $connection = $followed?->connection;
        if (! $followed || ! $connection || ! $connection->usesApp() || $connection->github_installation_id !== $followed->github_installation_id || ! $connection->project) {
            self::done($delivery, 'ignored', 'No project follows this repository.');

            return;
        }

        $fullName = mb_substr((string) data_get($payload, 'repository.full_name', ''), 0, 200);

        DB::transaction(function () use ($delivery, $payload, $connection, $followed, $fullName) {
            // One connection at a time, so deployment windows and branch history stay consistent.
            $locked = GithubConnection::lockForUpdate()->find($connection->id);
            $locked->setRelation('project', $connection->project);
            if ($fullName !== '' && $followed->full_name !== $fullName) {
                $followed->update(['full_name' => $fullName]); // renamed / transferred
            }

            (new DevelopmentSync($locked))->handle($delivery->event, $payload, $delivery->created_at);
            $locked->forceFill(['last_delivery_at' => now(), 'last_event' => $delivery->event])->save();
            if ($locked->access_lost_at === null && $locked->last_error !== null) {
                $locked->forceFill(['last_error' => null])->save();
            }
            $delivery->forceFill(['github_connection_id' => $locked->id])->save();
            self::done($delivery, 'processed');
        });
    }

    /** @return iterable<GithubConnection> */
    private static function connections(GithubInstallation $installation): iterable
    {
        return GithubConnection::where('github_installation_id', $installation->id)->where('mode', GithubConnection::MODE_APP)->with('project')->get();
    }

    private static function done(GithubAppDelivery $delivery, string $status, ?string $note = null): void
    {
        $delivery->update(['status' => $status, 'processed_at' => now(), 'error' => $note]);
    }
}
