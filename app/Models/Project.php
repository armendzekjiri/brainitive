<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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
        'changelog_token',
    ];

    protected $hidden = ['changelog_token'];

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

    /** The client this project is billed to (v1.38.0); set by workspace admins only. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
     * Get all plans (Gantt planners) in this project.
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class)->orderBy('position');
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
     * Get all items in this project (across its boards).
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * Get all helpdesks in this project.
     */
    public function helpdesks(): HasMany
    {
        return $this->hasMany(Helpdesk::class)->orderBy('position');
    }

    /**
     * Lead generation forms (BRAI-93), each with its own leads board.
     */
    public function leadForms(): HasMany
    {
        return $this->hasMany(LeadForm::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Get all pages in this project (root level).
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->whereNull('parent_id')->orderBy('position');
    }

    /**
     * Linked content between this project's issues, docs, tickets and plans (BRAI-71).
     */
    public function contentReferences(): HasMany
    {
        return $this->hasMany(ContentReference::class);
    }

    /** WHEN / IF / THEN rules (v1.40.0, BRAI-63); also the scoped binding of `{automationRule}`. */
    public function automationRules(): HasMany
    {
        return $this->hasMany(AutomationRule::class);
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
     * Custom fields for this project's board issues (archived ones included).
     */
    public function itemFields(): HasMany
    {
        return $this->hasMany(ItemField::class);
    }

    /**
     * Get all item priorities for this project.
     */
    public function itemPriorities(): HasMany
    {
        return $this->hasMany(ItemPriority::class);
    }

    /**
     * Users who can open this project: its members plus workspace owners / admins
     * (the same people as User::hasProjectAccess()).
     */
    public function accessibleUsers(): Builder
    {
        return User::query()->where(fn ($q) => $q
            ->whereHas('projects', fn ($p) => $p->where('projects.id', $this->id))
            ->orWhereHas('workspaces', fn ($w) => $w->where('workspaces.id', $this->workspace_id)->whereIn('workspace_user.role', ['owner', 'admin'])));
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
     * Default (instance-wide) item types this project switched off. Stored per project:
     * the default types are shared by every workspace, so a project never changes them.
     *
     * @return list<int>
     */
    public function disabledItemTypeIds(): array
    {
        return array_values(array_map('intval', $this->settings['disabled_item_types'] ?? []));
    }

    /**
     * Get the next item key for this project.
     */
    public function getNextItemKey(): string
    {
        return $this->key.'-'.$this->bumpCounter('next_item_number');
    }

    /**
     * Get the next ticket key for this project.
     */
    public function getNextTicketKey(): string
    {
        // this counter holds the next number to use (the issue counter holds the last one used)
        return $this->key.'-T'.($this->bumpCounter('next_ticket_number') - 1);
    }

    /**
     * Increment a key counter under a row lock and return the new value. Reading the number from
     * the loaded model and incrementing afterwards let parallel requests share a number (duplicate
     * key → 500 on simultaneous ticket submissions, found by the e2e suite).
     */
    private function bumpCounter(string $column): int
    {
        $value = DB::transaction(function () use ($column) {
            $current = (int) static::whereKey($this->getKey())->lockForUpdate()->value($column);
            static::whereKey($this->getKey())->update([$column => $current + 1]);

            return $current + 1;
        });

        $this->setAttribute($column, $value);
        $this->syncOriginalAttribute($column);

        return $value;
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function githubConnection(): HasOne
    {
        return $this->hasOne(GithubConnection::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /** Saved status updates (BRAI-64). */
    public function healthReports(): HasMany
    {
        return $this->hasMany(ProjectHealthReport::class);
    }
}
