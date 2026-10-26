<?php

namespace App\Support\Github;

use App\Models\GithubConnection;
use App\Models\ItemDevLink;
use Illuminate\Support\Facades\DB;

/**
 * "Refresh" for a GitHub App connection (BRAI-61): re-reads the installation's repositories and the
 * open linked pull requests, so missed or failed deliveries catch up (a PR merged meanwhile still
 * runs its merge transition). Read-only towards GitHub.
 *
 * Backoff: a rate limit stores `rate_limited_until` (GitHub's retry-after / reset time) and nothing
 * is called until then. Tokens never leave GithubApi.
 */
final class ConnectionRefresh
{
    public const MAX_PULL_REQUESTS = 50;

    private const REPO_PATTERN = '/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/';

    /** @return string what happened, for the log / tests: ok | rate_limited | lost | failed | skipped */
    public static function run(GithubConnection $connection): string
    {
        if (! $connection->usesApp()) {
            return 'skipped';
        }
        if ($connection->rate_limited_until?->isFuture()) {
            return 'rate_limited';
        }

        $api = GithubApi::make();
        $installation = $connection->installation;
        if (! $api) {
            $connection->forceFill(['last_error' => 'No GitHub App is configured on this instance.'])->save();

            return 'failed';
        }
        if (! $installation || $installation->removed_at) {
            ConnectionAccess::lost($connection, 'The GitHub App was uninstalled. Connect GitHub again to resume syncing.');

            return 'lost';
        }

        try {
            $repos = $api->installationRepositories($installation->installation_id);
            $installation->forceFill(['repositories' => $repos, 'refreshed_at' => now(), 'suspended_at' => null])->save();

            $visible = array_column($repos, 'id');
            $lost = [];
            foreach ($connection->followedRepositories()->get() as $followed) {
                $isVisible = in_array($followed->repo_id, $visible, true);
                if (! $isVisible && $followed->removed_at === null) {
                    $followed->update(['removed_at' => now()]);
                }
                if ($isVisible && $followed->removed_at !== null) {
                    $followed->update(['removed_at' => null]);
                }
                if (! $isVisible) {
                    $lost[] = $followed->full_name;
                }
            }

            $active = $connection->followedRepositories()->whereNull('removed_at')->pluck('full_name')->all();
            $links = ItemDevLink::where('github_connection_id', $connection->id)
                ->where('kind', 'pull_request')
                ->whereIn('state', ['open', 'draft'])
                ->whereIn('repository', $active)
                ->orderByDesc('occurred_at')
                ->get(['repository', 'identifier'])
                ->unique(fn (ItemDevLink $l) => $l->repository.'#'.$l->identifier)
                ->take(self::MAX_PULL_REQUESTS);

            foreach ($links as $link) {
                if (! preg_match(self::REPO_PATTERN, $link->repository) || ! ctype_digit($link->identifier)) {
                    continue;
                }
                try {
                    $pr = $api->pullRequest($installation->installation_id, $link->repository, (int) $link->identifier);
                } catch (GithubRateLimited $e) {
                    throw $e;
                } catch (GithubApiException $e) {
                    if ($e->status === 404) {
                        continue; // deleted, or no longer visible
                    }
                    throw $e;
                }

                DB::transaction(function () use ($connection, $link, $pr) {
                    $locked = GithubConnection::lockForUpdate()->find($connection->id);
                    $locked->setRelation('project', $connection->project);
                    (new DevelopmentSync($locked))->applyPullRequest($link->repository, $pr);
                });
            }

            $connection->refresh();
            $connection->forceFill(['last_refreshed_at' => now(), 'rate_limited_until' => null])->save();
            if ($lost !== []) {
                ConnectionAccess::lost($connection, implode(', ', $lost).(count($lost) === 1 ? ' is' : ' are').' no longer shared with the GitHub App. Add '.(count($lost) === 1 ? 'it' : 'them').' to the installation in GitHub to resume.');

                return 'lost';
            }
            ConnectionAccess::restored($connection);
            $connection->forceFill(['last_error' => null])->save();

            return 'ok';
        } catch (GithubRateLimited $e) {
            $connection->forceFill([
                'rate_limited_until' => $e->retryAt,
                'last_error' => 'GitHub’s rate limit was reached. Refresh again after '.$e->retryAt->format('H:i').' UTC.',
            ])->save();

            return 'rate_limited';
        } catch (GithubApiException $e) {
            if ($e->accessLost()) {
                $api->forgetInstallationToken($installation->installation_id);
                $installation->forceFill(['removed_at' => now()])->save();
                ConnectionAccess::lost($connection, 'GitHub no longer gives access to this installation (the app was uninstalled). Connect GitHub again to resume syncing.');

                return 'lost';
            }
            $connection->forceFill(['last_error' => 'Refreshing failed: '.$e->getMessage()])->save();

            return 'failed';
        }
    }
}
