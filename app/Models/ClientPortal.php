<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A project's client portal (v1.42.0, BRAI-73): `/client/{slug}`, off by default. Invited clients
 * (guest identities, never workspace members) see only the deliverables the team published here.
 * Access rules: App\Support\ClientPortal\ClientPortalAccess. Contract: docs/ai/tasks/release-v1.42.0.md.
 */
class ClientPortal extends Model
{
    public const SLUG_PATTERN = '[a-z0-9](?:[a-z0-9-]{1,58}[a-z0-9])?';

    protected $fillable = ['uuid', 'project_id', 'slug', 'title', 'welcome', 'enabled', 'created_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (ClientPortal $portal) {
            $portal->uuid ??= (string) Str::uuid();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ClientPortalMember::class);
    }

    public function deliverables(): HasMany
    {
        return $this->hasMany(ClientDeliverable::class);
    }

    /** A free slug derived from a name (the project's), for the first "enable". */
    public static function suggestSlug(string $name): string
    {
        $base = Str::limit(trim(Str::slug($name), '-'), 50, '') ?: 'client';
        $base = strlen($base) < 3 ? $base.'-portal' : $base;
        $slug = $base;
        for ($i = 2; self::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    public function publicUrl(): string
    {
        return route('client-portal.show', $this->slug);
    }

    public function publicPath(): string
    {
        return '/client/'.$this->slug;
    }
}
