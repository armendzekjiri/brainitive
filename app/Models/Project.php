<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'uuid',
        'name',
        'slug',
        'key',
        'description',
        'created_by',
        'icon',
        'color',
        'settings',
        'next_item_number',
        'next_ticket_number',
    ];

    protected $appends = ['icon_display'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /** For frontend: emoji or full URL when icon is a stored image path. */
    public function getIconDisplayAttribute(): ?string
    {
        $icon = $this->attributes['icon'] ?? null;
        if (! $icon) {
            return null;
        }
        if (str_contains($icon, '/') || strlen($icon) > 30) {
            return asset('storage/'.$icon);
        }
        return $icon;
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Project $project) {
            if (empty($project->slug)) {
                $project->slug = Str::slug($project->name);
            }
            if (empty($project->key)) {
                $project->key = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $project->name), 0, 4));
            }
            if (empty($project->uuid)) {
                $project->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the workspace this project belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the user who created this project.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all users assigned to this project.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * Get users with a specific role.
     */
    public function usersWithRole(string $role): BelongsToMany
    {
        return $this->users()->wherePivot('role', $role);
    }

    /**
     * Get admins of this project.
     */
    public function admins(): BelongsToMany
    {
        return $this->usersWithRole('admin');
    }

    /**
     * Get all task lists in this project.
     */
    public function taskLists(): HasMany
    {
        return $this->hasMany(TaskList::class)->orderBy('position');
    }

    /**
     * Get all boards in this project.
     */
    public function boards(): HasMany
    {
        return $this->hasMany(Board::class)->orderBy('position');
    }

    /**
     * Get all helpdesks in this project.
     */
    public function helpdesks(): HasMany
    {
        return $this->hasMany(Helpdesk::class)->orderBy('position');
    }

    /**
     * Get all pages in this project (root level).
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->whereNull('parent_id')->orderBy('position');
    }

    /**
     * Get all pages including nested ones.
     */
    public function allPages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    /**
     * Get all labels for this project.
     */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    /**
     * Get all item types for this project.
     */
    public function itemTypes(): HasMany
    {
        return $this->hasMany(ItemType::class);
    }

    /**
     * Get all item priorities for this project.
     */
    public function itemPriorities(): HasMany
    {
        return $this->hasMany(ItemPriority::class);
    }

    /**
     * Add a user to this project with a role.
     */
    public function addUser(User $user, string $role = 'viewer', ?User $assignedBy = null): void
    {
        $this->users()->attach($user->id, [
            'role' => $role,
            'assigned_by' => $assignedBy?->id,
        ]);
    }

    /**
     * Update a user's role in this project.
     */
    public function updateUserRole(User $user, string $role): void
    {
        $this->users()->updateExistingPivot($user->id, ['role' => $role]);
    }

    /**
     * Remove a user from this project.
     */
    public function removeUser(User $user): void
    {
        $this->users()->detach($user->id);
    }

    /**
     * Check if a user is in this project.
     */
    public function hasUser(User $user): bool
    {
        return $this->users()->where('user_id', $user->id)->exists();
    }

    /**
     * Get the next item key for this project.
     */
    public function getNextItemKey(): string
    {
        $this->increment('next_item_number');

        return $this->key . '-' . $this->next_item_number;
    }

    /**
     * Get the next ticket key for this project.
     */
    public function getNextTicketKey(): string
    {
        $key = $this->key . '-T' . $this->next_ticket_number;
        $this->increment('next_ticket_number');
        return $key;
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
