<?php

namespace App\Models;

use App\Support\ApiKeys\ApiKeyAreas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A personal API key of the Developer API (v1.52.0, App\Support\ApiKeys\ApiKeys): `Bearer brai_…` on /api/v1,
 * acting as its user but never beyond its scope ("where": scope_type + scope_ids) and its areas ("what":
 * abilities + can_delete). Only the sha256 of the key is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $prefix
 * @property string $token_hash
 * @property string $scope_type all | workspaces | projects | boards
 * @property list<int>|null $scope_ids
 * @property array<string, string> $abilities area => none | read | write
 * @property bool $can_delete
 * @property int $credential_generation users.app_auth_generation at creation / regeneration
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property string|null $created_from_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApiKey extends Model
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_WORKSPACES = 'workspaces';

    public const SCOPE_PROJECTS = 'projects';

    public const SCOPE_BOARDS = 'boards';

    public const SCOPE_TYPES = [self::SCOPE_ALL, self::SCOPE_WORKSPACES, self::SCOPE_PROJECTS, self::SCOPE_BOARDS];

    // ownership, the hash and the usage columns are set by ApiKeys only (forceFill)
    protected $fillable = ['name'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scope_ids' => 'array',
            'abilities' => 'array',
            'can_delete' => 'boolean',
            'credential_generation' => 'integer',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Not revoked and not expired. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && ! $this->expires_at->isFuture();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ! $this->isExpired();
    }

    /** The key's level for an area: none | read | write (unknown areas: none). */
    public function level(string $area): string
    {
        $level = $this->abilities[$area] ?? ApiKeyAreas::NONE;

        return in_array($level, ApiKeyAreas::LEVELS, true) ? $level : ApiKeyAreas::NONE;
    }

    /** @return list<int> */
    public function scopeIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->scope_ids ?? [])));
    }
}
