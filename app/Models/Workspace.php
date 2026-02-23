<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Workspace extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'description',
        'owner_id',
        'logo',
        'header_image',
        'website',
        'industry',
        'company_size',
        'timezone',
        'language',
        'currency',
        'is_public',
        'social_links',
        'branding',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'social_links' => 'array',
            'branding' => 'array',
            'is_public' => 'boolean',
        ];
    }

    protected $appends = ['logo_display', 'header_image_display'];

    /** Full URL for workspace logo (for frontend). */
    public function getLogoDisplayAttribute(): ?string
    {
        $path = $this->attributes['logo'] ?? null;
        return $path ? asset('storage/'.$path) : null;
    }

    /** Full URL for workspace header/cover image (for frontend). */
    public function getHeaderImageDisplayAttribute(): ?string
    {
        $path = $this->attributes['header_image'] ?? null;
        return $path ? asset('storage/'.$path) : null;
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Workspace $workspace) {
            if (empty($workspace->slug)) {
                $workspace->slug = Str::slug($workspace->name);
            }
            if (empty($workspace->uuid)) {
                $workspace->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the owner of this workspace.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get all users in this workspace.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(['role', 'invited_at', 'accepted_at', 'invited_by'])
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
     * Get admins of this workspace.
     */
    public function admins(): BelongsToMany
    {
        return $this->usersWithRole('admin');
    }

    /**
     * Get editors of this workspace.
     */
    public function editors(): BelongsToMany
    {
        return $this->usersWithRole('editor');
    }

    /**
     * Get viewers of this workspace.
     */
    public function viewers(): BelongsToMany
    {
        return $this->usersWithRole('viewer');
    }

    /**
     * Get all projects in this workspace.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Get all bookmarks in this workspace.
     */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class)->orderBy('position');
    }

    /**
     * Get pending invitations for this workspace.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /**
     * Add a user to this workspace with a role.
     */
    public function addUser(User $user, string $role = 'viewer', ?User $invitedBy = null): void
    {
        $this->users()->attach($user->id, [
            'role' => $role,
            'invited_at' => now(),
            'accepted_at' => now(),
            'invited_by' => $invitedBy?->id,
        ]);
    }

    /**
     * Update a user's role in this workspace.
     */
    public function updateUserRole(User $user, string $role): void
    {
        $this->users()->updateExistingPivot($user->id, ['role' => $role]);
    }

    /**
     * Remove a user from this workspace.
     */
    public function removeUser(User $user): void
    {
        $this->users()->detach($user->id);
    }

    /**
     * Check if a user is in this workspace.
     */
    public function hasUser(User $user): bool
    {
        return $this->users()->where('user_id', $user->id)->exists();
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
