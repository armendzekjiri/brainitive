<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A project's GitHub connection. Two modes:
 * - `webhook` (legacy): repos post to /webhooks/github/{uuid}, signed with `secret`;
 * - `app` (BRAI-61): the instance's GitHub App delivers to /webhooks/github-app and the project
 *   follows some of its installation's repositories (GithubRepository).
 */
class GithubConnection extends Model
{
    public const MODE_WEBHOOK = 'webhook';

    public const MODE_APP = 'app';

    protected $fillable = ['uuid', 'project_id', 'secret', 'created_by', 'repositories', 'deployments', 'last_delivery_at', 'last_event', 'last_error',
        'mode', 'github_installation_id', 'access_lost_at', 'rate_limited_until', 'last_refreshed_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'repositories' => 'array',
            'deployments' => 'array',
            'last_delivery_at' => 'datetime',
            'access_lost_at' => 'datetime',
            'rate_limited_until' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    public static function newSecret(): string
    {
        return Str::random(40);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(GithubDelivery::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(ItemDevLink::class);
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(GithubInstallation::class, 'github_installation_id');
    }

    /** Repositories followed through the GitHub App. */
    public function followedRepositories(): HasMany
    {
        return $this->hasMany(GithubRepository::class);
    }

    public function usesApp(): bool
    {
        return $this->mode === self::MODE_APP;
    }

    public function webhookUrl(): string
    {
        return route('webhooks.github', $this->uuid);
    }

    public function rememberRepository(string $fullName): void
    {
        $repos = $this->repositories ?? [];
        if (! in_array($fullName, $repos, true)) {
            $repos[] = $fullName;
            sort($repos);
            $this->repositories = array_slice($repos, 0, 50);
        }
    }
}
