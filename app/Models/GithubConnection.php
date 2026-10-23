<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A project's GitHub webhook: repos post to /webhooks/github/{uuid}, signed with `secret`.
 */
class GithubConnection extends Model
{
    protected $fillable = ['uuid', 'project_id', 'secret', 'created_by', 'repositories', 'deployments', 'last_delivery_at', 'last_event', 'last_error'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'repositories' => 'array',
            'deployments' => 'array',
            'last_delivery_at' => 'datetime',
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
