<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Helpdesk extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'uuid',
        'name',
        'slug',
        'description',
        'position',
        'created_by',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Helpdesk $helpdesk) {
            if (empty($helpdesk->slug)) {
                $helpdesk->slug = Str::slug($helpdesk->name);
            }
            if (empty($helpdesk->uuid)) {
                $helpdesk->uuid = (string) Str::uuid();
            }
        });

        // Create default columns when a helpdesk is created
        static::created(function (Helpdesk $helpdesk) {
            $defaultColumns = [
                ['name' => 'Open', 'slug' => 'open', 'position' => 0, 'color' => '#6b7280'],
                ['name' => 'In Progress', 'slug' => 'in-progress', 'position' => 1, 'color' => '#3b82f6'],
                ['name' => 'Resolved', 'slug' => 'resolved', 'position' => 2, 'color' => '#22c55e', 'is_done_column' => true],
            ];

            foreach ($defaultColumns as $column) {
                $helpdesk->columns()->create($column);
            }
        });
    }

    /**
     * Get the project this helpdesk belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user who created this helpdesk.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all columns in this helpdesk.
     */
    public function columns(): HasMany
    {
        return $this->hasMany(HelpdeskColumn::class)->orderBy('position');
    }

    /**
     * Get all tickets in this helpdesk.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Get all custom fields for this helpdesk.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(HelpdeskField::class)->orderBy('position');
    }

    /**
     * Get tickets grouped by column.
     */
    public function ticketsByColumn(): array
    {
        $columns = $this->columns()->with(['tickets' => function ($query) {
            $query->orderBy('position');
        }])->get();

        return $columns->mapWithKeys(function ($column) {
            return [$column->id => $column->tickets];
        })->toArray();
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}