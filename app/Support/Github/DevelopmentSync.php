<?php

namespace App\Support\Github;

use App\Models\GithubConnection;
use App\Models\Item;
use App\Models\ItemDevLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns one GitHub webhook payload into branch / commit / PR / deployment links on the
 * project's issues. Every write is an upsert keyed by (item, kind, repo, identifier), so a
 * redelivered or retried event changes nothing twice.
 */
class DevelopmentSync
{
    public const EVENTS = ['ping', 'push', 'create', 'delete', 'pull_request', 'deployment_status'];

    private const MAX_COMMITS = 50;

    public function __construct(private GithubConnection $connection) {}

    public function handle(string $event, array $payload, ?Carbon $receivedAt = null): void
    {
        $receivedAt ??= now();
        $repo = (string) data_get($payload, 'repository.full_name', '');
        if ($repo === '') {
            return;
        }
        $this->connection->rememberRepository($repo);

        match ($event) {
            'push' => $this->push($repo, $payload, $receivedAt),
            'create' => data_get($payload, 'ref_type') === 'branch' ? $this->branch($repo, (string) data_get($payload, 'ref'), 'active', $receivedAt) : null,
            'delete' => data_get($payload, 'ref_type') === 'branch' ? $this->branchDeleted($repo, (string) data_get($payload, 'ref')) : null,
            'pull_request' => $this->pullRequest($repo, $payload),
            'deployment_status' => $this->deployment($repo, $payload),
            default => null,
        };

        $this->connection->save();
    }

    private function push(string $repo, array $payload, Carbon $receivedAt): void
    {
        $ref = (string) data_get($payload, 'ref', '');
        if (! str_starts_with($ref, 'refs/heads/')) {
            return; // tags
        }
        $branch = Str::after($ref, 'refs/heads/');

        if (data_get($payload, 'deleted')) {
            $this->branchDeleted($repo, $branch);

            return;
        }

        $this->branch($repo, $branch, 'active', $receivedAt);

        foreach (array_slice((array) data_get($payload, 'commits', []), 0, self::MAX_COMMITS) as $commit) {
            $sha = (string) data_get($commit, 'id', '');
            $message = (string) data_get($commit, 'message', '');
            if (! preg_match('/^[0-9a-f]{7,64}$/', $sha)) {
                continue;
            }

            foreach ($this->items(IssueKeys::find($message, $this->projectKey())) as $item) {
                $link = ItemDevLink::firstOrNew(['item_id' => $item->id, 'kind' => 'commit', 'repository' => $repo, 'identifier' => $sha]);
                $meta = $link->meta ?? [];
                // When the commit reached each branch — used to tell which deployment shipped it.
                $meta['branches'][$branch] ??= $receivedAt->toIso8601String();
                $link->fill([
                    'github_connection_id' => $this->connection->id,
                    'title' => Str::limit(Str::before($message, "\n"), 480),
                    'url' => $this->githubUrl(data_get($commit, 'url')),
                    'author' => Str::limit((string) (data_get($commit, 'author.username') ?: data_get($commit, 'author.name')), 190) ?: null,
                    'state' => 'committed',
                    'meta' => $meta,
                    'occurred_at' => $this->time(data_get($commit, 'timestamp')) ?? $receivedAt,
                ])->save();
            }
        }
    }

    private function branch(string $repo, string $branch, string $state, Carbon $at): void
    {
        if ($branch === '') {
            return;
        }
        foreach ($this->items(IssueKeys::find($branch, $this->projectKey())) as $item) {
            $link = ItemDevLink::firstOrNew(['item_id' => $item->id, 'kind' => 'branch', 'repository' => $repo, 'identifier' => Str::limit($branch, 250, '')]);
            $link->fill([
                'github_connection_id' => $this->connection->id,
                'title' => Str::limit($branch, 480),
                'url' => 'https://github.com/'.$repo.'/tree/'.implode('/', array_map('rawurlencode', explode('/', $branch))),
                'state' => $state,
                'occurred_at' => $link->exists && $link->state === 'active' ? $link->occurred_at : $at,
            ])->save();
        }
    }

    private function branchDeleted(string $repo, string $branch): void
    {
        ItemDevLink::where('github_connection_id', $this->connection->id)
            ->where(['kind' => 'branch', 'repository' => $repo, 'identifier' => $branch])
            ->update(['state' => 'deleted']);
    }

    private function pullRequest(string $repo, array $payload): void
    {
        $pr = (array) data_get($payload, 'pull_request', []);
        $number = (int) data_get($pr, 'number');
        if ($number < 1) {
            return;
        }

        $text = data_get($pr, 'title').' '.data_get($pr, 'head.ref');
        $merged = (bool) data_get($pr, 'merged');
        $state = $merged ? 'merged' : (data_get($pr, 'state') === 'closed' ? 'closed' : (data_get($pr, 'draft') ? 'draft' : 'open'));

        foreach ($this->items(IssueKeys::find($text, $this->projectKey())) as $item) {
            ItemDevLink::updateOrCreate(
                ['item_id' => $item->id, 'kind' => 'pull_request', 'repository' => $repo, 'identifier' => (string) $number],
                [
                    'github_connection_id' => $this->connection->id,
                    'title' => Str::limit((string) data_get($pr, 'title'), 480),
                    'url' => $this->githubUrl(data_get($pr, 'html_url')),
                    'author' => Str::limit((string) data_get($pr, 'user.login'), 190) ?: null,
                    'state' => $state,
                    'meta' => [
                        'base' => (string) data_get($pr, 'base.ref'),
                        'head' => (string) data_get($pr, 'head.ref'),
                        'merged_at' => $merged ? data_get($pr, 'merged_at') : null,
                    ],
                    'occurred_at' => $this->time(data_get($pr, 'updated_at')) ?? now(),
                ],
            );
        }
    }

    /**
     * A deployment of `ref` to an environment ships the deployed sha plus everything that reached
     * `ref` (commits pushed, PRs merged) after the previous successful deployment there.
     */
    private function deployment(string $repo, array $payload): void
    {
        $environment = Str::limit((string) data_get($payload, 'deployment.environment', ''), 120, '');
        $ref = (string) data_get($payload, 'deployment.ref', '');
        $sha = (string) data_get($payload, 'deployment.sha', '');
        $state = (string) data_get($payload, 'deployment_status.state', '');
        $at = $this->time(data_get($payload, 'deployment_status.created_at')) ?? now();
        if ($environment === '' || $state === '') {
            return;
        }

        $windowKey = $repo.'|'.$environment;
        $since = isset($this->connection->deployments[$windowKey]) ? Carbon::parse($this->connection->deployments[$windowKey]) : null;
        $inWindow = fn (?string $time) => $time !== null && ($t = Carbon::parse($time))->lte($at) && ($since === null || $t->gt($since));

        $itemIds = ItemDevLink::where('github_connection_id', $this->connection->id)
            ->where('repository', $repo)
            ->whereIn('kind', ['commit', 'pull_request'])
            ->latest('id')
            ->limit(5000)
            ->get(['item_id', 'kind', 'identifier', 'state', 'meta'])
            ->filter(fn (ItemDevLink $l) => $l->kind === 'commit'
                ? ($sha !== '' && $l->identifier === $sha) || $inWindow($l->meta['branches'][$ref] ?? null)
                : $l->state === 'merged' && ($l->meta['base'] ?? null) === $ref && $inWindow($l->meta['merged_at'] ?? null))
            ->pluck('item_id')
            ->unique();

        $url = $this->githubUrl(data_get($payload, 'deployment_status.environment_url'), allowAny: true)
            ?? $this->githubUrl(data_get($payload, 'deployment_status.target_url'))
            ?? $this->githubUrl(data_get($payload, 'deployment_status.log_url'));

        foreach ($itemIds as $itemId) {
            $link = ItemDevLink::firstOrNew(['item_id' => $itemId, 'kind' => 'deployment', 'repository' => $repo, 'identifier' => $environment]);
            if ($link->exists && $link->occurred_at?->gt($at)) {
                continue; // an older status arriving late
            }
            $link->fill([
                'github_connection_id' => $this->connection->id,
                'title' => $environment,
                'url' => $url,
                'state' => $state,
                'meta' => ['ref' => $ref, 'sha' => substr($sha, 0, 40)],
                'occurred_at' => $at,
            ])->save();
        }

        if ($state === 'success') {
            $deployments = $this->connection->deployments ?? [];
            $deployments[$windowKey] = $at->toIso8601String();
            $this->connection->deployments = $deployments;
        }
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<int, Item>
     */
    private function items(array $keys): Collection
    {
        return $keys === [] ? collect() : $this->connection->project->items()->whereIn('key', $keys)->get(['items.id']);
    }

    private function projectKey(): string
    {
        return (string) $this->connection->project->key;
    }

    /** Only https links (github.com unless $allowAny, e.g. the deployed site's URL). */
    private function githubUrl(mixed $url, bool $allowAny = false): ?string
    {
        if (! is_string($url) || strlen($url) > 490 || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return $allowAny || str_starts_with($url, 'https://github.com/') ? $url : null;
    }

    private function time(mixed $value): ?Carbon
    {
        try {
            return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
