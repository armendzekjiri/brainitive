<?php

namespace App\Models;

use App\Support\References\ContentReferences;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A Tom's Planner–style plan: an outline of rows with bars, milestones and dependencies on
 * a timeline. The whole plan is one JSON document (`data`) saved with a revision guard.
 */
class Plan extends Model
{
    use HasFactory, SoftDeletes;

    /** A new history checkpoint is recorded at most this often while autosaving. */
    public const VERSION_INTERVAL_MINUTES = 30;

    public const ZOOM_LEVELS = ['day', 'week', 'month', 'quarter'];

    public const COLUMNS = ['who', 'status', 'start', 'end', 'duration', 'progress', 'notes'];

    protected $fillable = [
        'uuid',
        'project_id',
        'name',
        'slug',
        'description',
        'data',
        'settings',
        'edit_revision',
        'current_version',
        'share_token',
        'position',
        'created_by',
        'last_edited_by',
    ];

    protected $attributes = [
        'edit_revision' => 0,
        'current_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'settings' => 'array',
            'edit_revision' => 'integer',
            'current_version' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Plan $plan) {
            $plan->uuid ??= (string) Str::uuid();
            $plan->data ??= self::emptyData();
            $plan->settings = array_replace(self::defaultSettings(), $plan->settings ?? []);
        });

        // Rows linked to issues are derived references (BRAI-71); every write path saves the model.
        static::saved(function (Plan $plan) {
            if ($plan->wasRecentlyCreated || $plan->wasChanged('data')) {
                ContentReferences::syncPlan($plan);
            }
        });
        static::forceDeleted(fn (Plan $plan) => ContentReferences::pruneMissing((int) $plan->project_id));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array{rows: list<array<string, mixed>>, dependencies: list<array<string, mixed>>}
     */
    public static function emptyData(): array
    {
        return ['rows' => [], 'dependencies' => []];
    }

    /**
     * @return array{zoom: string, columns: array<string, bool>, gridWidth: int, showWeekends: bool}
     */
    public static function defaultSettings(): array
    {
        return [
            'zoom' => 'week',
            'columns' => ['who' => true, 'status' => true, 'start' => true, 'end' => true, 'duration' => true, 'progress' => false, 'notes' => false],
            'gridWidth' => 560,
            'showWeekends' => true,
        ];
    }

    /**
     * Slug unique within the project (soft-deleted plans included, they keep theirs).
     */
    public static function uniqueSlug(Project $project, string $name, ?int $exceptId = null): string
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;
        $counter = 1;

        while (self::withTrashed()
            ->where('project_id', $project->id)
            ->where('slug', $slug)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class)->orderByDesc('version');
    }

    /**
     * Save the document and keep a useful history without a version per keystroke: a
     * checkpoint is recorded on an explicit save, when the newest version is older than the
     * interval, or when someone else saved last.
     *
     * @param  array{rows: list<array<string, mixed>>, dependencies: list<array<string, mixed>>}  $data
     * @param  array<string, mixed>  $settings
     */
    public function saveDocument(User $user, array $data, array $settings, bool $checkpoint = false): ?PlanVersion
    {
        $this->update([
            'data' => $data,
            'settings' => array_replace(self::defaultSettings(), $settings),
            'last_edited_by' => $user->id,
            'edit_revision' => $this->edit_revision + 1,
        ]);

        $latest = $this->versions()->first(['id', 'created_by', 'created_at']);
        $isStale = $latest === null
            || $latest->created_at->lt(now()->subMinutes(self::VERSION_INTERVAL_MINUTES))
            || $latest->created_by !== $user->id;

        return $checkpoint || $isStale ? $this->recordVersion($user) : null;
    }

    /**
     * Make an old version the current document; the restore itself becomes a new version.
     */
    public function restoreVersion(PlanVersion $version, User $user): PlanVersion
    {
        $this->update([
            'data' => $version->data ?? self::emptyData(),
            'settings' => array_replace(self::defaultSettings(), $version->settings ?? []),
            'last_edited_by' => $user->id,
            'edit_revision' => $this->edit_revision + 1,
        ]);

        return $this->recordVersion($user, "Restored from version {$version->version}");
    }

    public function recordVersion(?User $user = null, ?string $message = null): PlanVersion
    {
        $this->increment('current_version');

        return $this->versions()->create([
            'version' => $this->current_version,
            'name' => $this->name,
            'data' => $this->data,
            'settings' => $this->settings,
            'message' => $message,
            'created_by' => $user?->id,
        ]);
    }

    /**
     * Earliest bar start and latest bar end of the plan, as Y-m-d strings.
     *
     * @return array{start: string|null, end: string|null}
     */
    public function dateRange(): array
    {
        $bars = collect($this->data['rows'] ?? [])->flatMap(fn (array $row) => $row['bars'] ?? []);

        return ['start' => $bars->min('start'), 'end' => $bars->max('end')];
    }
}
