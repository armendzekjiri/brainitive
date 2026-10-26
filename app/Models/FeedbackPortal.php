<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A project's public feedback portal (v1.42.0, BRAI-60): off by default, one per project, public at
 * /feedback/{slug} while enabled. Project admins configure and moderate it (ProjectPolicy::update).
 */
class FeedbackPortal extends Model
{
    public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,58})[a-z0-9]$/';

    protected $fillable = ['project_id', 'enabled', 'slug', 'title', 'intro', 'created_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (FeedbackPortal $portal) {
            $portal->uuid ??= (string) Str::uuid();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(FeedbackPost::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(FeedbackEvent::class);
    }

    /** Enabled portals of live (not deleted) projects — what the public routes may show. */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('enabled', true)->whereHas('project');
    }

    /** The public portal for a slug, or 404. */
    public static function findPublic(string $slug): self
    {
        return self::public()->where('slug', $slug)->with('project:id,name,workspace_id')->firstOrFail();
    }

    /** A free slug suggestion from the project's name. */
    public static function suggestSlug(Project $project): string
    {
        $base = Str::limit(Str::slug($project->name) ?: 'feedback', 50, '');
        $base = strlen($base) < 3 ? 'feedback-'.$base : $base;
        $slug = trim($base, '-');
        $i = 1;
        while (self::where('slug', $slug)->exists()) {
            $slug = trim($base, '-').'-'.(++$i);
        }

        return $slug;
    }

    /** The next post number, under a row lock (parallel submissions never share a number). */
    public function nextNumber(): int
    {
        return DB::transaction(function () {
            $locked = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $locked->increment('next_number');

            return (int) $locked->next_number;
        });
    }

    public function publicUrl(): string
    {
        return route('feedback.portal', $this->slug);
    }
}
