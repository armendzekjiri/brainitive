<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * One "Export project" request (v1.41.0, BRAI-81). The ZIP (ProjectArchiveWriter) is on the private
 * `local` disk under exports/<uuid>/ and can be downloaded through a signed link by the person who
 * exported it for 24 h; then the file is deleted (status expired). Rows are pruned after 30 days.
 */
class ProjectExport extends Model
{
    use Prunable;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    /** How long a finished export can be downloaded. */
    public const TTL_HOURS = 24;

    protected $fillable = [
        'workspace_id', 'project_id', 'user_id', 'status', 'file_path', 'file_size', 'counts', 'error',
        'expires_at', 'finished_at',
    ];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'file_size' => 'integer',
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProjectExport $export) {
            $export->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY && $this->file_path !== null
            && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** A signed link (relative signature, so proxies don't break it) that stops working when the export expires. */
    public function downloadUrl(): ?string
    {
        if (! $this->isDownloadable()) {
            return null;
        }
        $workspace = $this->workspace;

        return url(URL::temporarySignedRoute('projects.export.download', $this->expires_at, [$workspace->slug, $this->uuid], absolute: false));
    }

    /** The export folder (ZIP and work files). */
    public function directory(): string
    {
        return 'exports/'.$this->uuid;
    }

    /** Delete the files and mark the export expired. */
    public function expire(): void
    {
        Storage::disk('local')->deleteDirectory($this->directory());
        $this->forceFill(['file_path' => null, 'status' => in_array($this->status, [self::READY, self::EXPIRED], true) ? self::EXPIRED : self::FAILED])->save();
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(30))->whereNotIn('status', [self::QUEUED, self::RUNNING]);
    }

    protected function pruning(): void
    {
        Storage::disk('local')->deleteDirectory($this->directory());
    }

    /** For the settings card. */
    public function present(): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status === self::READY && ! $this->isDownloadable() ? self::EXPIRED : $this->status,
            'file_size' => $this->file_size,
            'counts' => $this->counts,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'download_url' => $this->downloadUrl(),
        ];
    }
}
