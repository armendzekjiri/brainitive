<?php

namespace App\Models;

use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'google_id',
        'github_id',
        'avatar',
        'bio',
        'phone',
        'onboarding_state',
    ];

    protected $appends = ['avatar_display'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'onboarding_state' => 'array',
        ];
    }

    /** Full URL for profile picture (avatar). */
    public function getAvatarDisplayAttribute(): ?string
    {
        $avatar = $this->attributes['avatar'] ?? null;
        if (! $avatar) {
            return null;
        }

        return asset('storage/'.$avatar);
    }

    /**
     * Send the email verification notification.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Check if user is an instance admin.
     */
    public function isInstanceAdmin(): bool
    {
        return $this->instanceAdmin()->exists();
    }

    /**
     * Get the instance admin record for this user.
     */
    public function instanceAdmin(): HasOne
    {
        return $this->hasOne(InstanceAdmin::class);
    }

    /**
     * Get the workspaces this user belongs to.
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withPivot(['role', 'invited_at', 'accepted_at', 'invited_by'])
            ->withTimestamps();
    }

    /**
     * Get the workspaces owned by this user.
     */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_id');
    }

    /**
     * Get the projects this user belongs to.
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot(['role', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * Get the user's role in a specific workspace.
     */
    public function workspaceRole(Workspace $workspace): ?string
    {
        return $this->workspaces()
            ->where('workspace_id', $workspace->id)
            ->first()?->pivot->role;
    }

    /**
     * Get the user's role in a specific project.
     */
    public function projectRole(Project $project): ?string
    {
        return $this->projects()
            ->where('project_id', $project->id)
            ->first()?->pivot->role;
    }

    /**
     * Check if user has access to a workspace.
     */
    public function hasWorkspaceAccess(Workspace $workspace): bool
    {
        if ($this->isInstanceAdmin()) {
            return true;
        }

        return $this->workspaces()
            ->where('workspace_id', $workspace->id)
            ->exists();
    }

    /**
     * Check if user has access to a project.
     */
    public function hasProjectAccess(Project $project): bool
    {
        if ($this->isInstanceAdmin()) {
            return true;
        }

        // Workspace admins have access to all projects
        $workspaceRole = $this->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Check direct project assignment
        return $this->projects()
            ->where('project_id', $project->id)
            ->exists();
    }

    /**
     * Get items created by this user.
     */
    public function createdItems(): HasMany
    {
        return $this->hasMany(Item::class, 'created_by');
    }

    /**
     * Get items assigned to this user.
     */
    public function assignedItems(): HasMany
    {
        return $this->hasMany(Item::class, 'assigned_to');
    }

    /**
     * Get comments by this user.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Get tickets assigned to this user.
     */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }

    /**
     * Get ticket replies written by this user.
     */
    public function ticketReplies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    /**
     * Get tickets watched by this user.
     */
    public function watchedTickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_watchers')
            ->withPivot('created_at');
    }
}
