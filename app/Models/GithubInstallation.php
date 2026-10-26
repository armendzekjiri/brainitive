<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One installation of the instance's GitHub App on a user or organization account (BRAI-61).
 * Several projects may use one installation; each repository maps to one project
 * (GithubRepository). Never deleted when GitHub removes it: marked `removed_at` instead.
 */
class GithubInstallation extends Model
{
    protected $fillable = ['installation_id', 'account_login', 'account_type', 'repository_selection', 'repositories', 'suspended_at', 'removed_at', 'refreshed_at'];

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'repositories' => 'array',
            'suspended_at' => 'datetime',
            'removed_at' => 'datetime',
            'refreshed_at' => 'datetime',
        ];
    }

    public function connections(): HasMany
    {
        return $this->hasMany(GithubConnection::class);
    }

    public function followed(): HasMany
    {
        return $this->hasMany(GithubRepository::class);
    }

    public function isActive(): bool
    {
        return $this->removed_at === null && $this->suspended_at === null;
    }

    /** @return array{id: int, full_name: string, private: bool}|null */
    public function repository(int $repoId): ?array
    {
        foreach ($this->repositories ?? [] as $repo) {
            if ((int) ($repo['id'] ?? 0) === $repoId) {
                return $repo;
            }
        }

        return null;
    }
}
