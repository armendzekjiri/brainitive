<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A public booking page (v1.45.0, BRAI-158): `/book/{public_slug}`. A user has several, each with its
 * own busy sources (which calendars, issues and tasks block its time); confirmed bookings of all the
 * user's pages always block. The workspace is the operator shown on the page and where cards go.
 */
class BookingPage extends Model
{
    use HasFactory, SoftDeletes;

    /** Busy sources of a new page: every calendar, issues and tasks; all-day entries don't block. */
    public const DEFAULT_BUSY_SOURCES = ['connections' => 'all', 'issues' => true, 'tasks' => true, 'all_day_blocks' => false];

    protected $fillable = [
        'user_id', 'workspace_id', 'public_slug', 'title', 'intro', 'timezone', 'enabled',
        'busy_sources', 'card_board_id', 'card_column_id', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'busy_sources' => 'array',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BookingPage $page) {
            $page->uuid ??= (string) Str::uuid();
            $page->busy_sources ??= self::DEFAULT_BUSY_SOURCES;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function types(): HasMany
    {
        return $this->hasMany(BookingType::class)->orderBy('position')->orderBy('id');
    }

    public function cardBoard(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'card_board_id');
    }

    public function cardColumn(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'card_column_id');
    }

    /**
     * The busy sources with defaults filled in.
     *
     * @return array{connections: 'all'|list<int>, issues: bool, tasks: bool, all_day_blocks: bool}
     */
    public function busySources(): array
    {
        $sources = array_merge(self::DEFAULT_BUSY_SOURCES, (array) $this->busy_sources);
        $sources['connections'] = $sources['connections'] === 'all'
            ? 'all'
            : array_values(array_map('intval', array_filter((array) $sources['connections'], 'is_numeric')));

        return $sources;
    }

    /** A free, readable address like "erin-owner", "erin-owner-2", … */
    public static function freeSlug(string $base): string
    {
        $base = Str::limit(Str::slug($base) ?: 'book', 56, '');
        $slug = $base;
        for ($n = 2; self::withTrashed()->where('public_slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
