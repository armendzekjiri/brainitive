<?php

namespace App\Models;

use App\Notifications\VerifyEmailNotification;
use App\Support\ApiKeys\ApiKeyAccess;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
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
        'microsoft_id',
        'apple_id',
        'avatar',
        'bio',
        'phone',
        'onboarding_state',
        'calendar_view',
        'timezone',
        'task_digest',
        'notification_preferences',
        'sidebar_preferences',
        'terms_accepted_at',
        'terms_version',
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
        'app_auth_generation',
        'apple_refresh_token',
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
            'suspended_at' => 'datetime',
            'last_login_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
            'onboarding_state' => 'array',
            'task_digest' => 'boolean',
            'notification_preferences' => 'array',
            'sidebar_preferences' => 'array',
            'app_auth_generation' => 'integer',
            // Sign in with Apple: only kept to revoke the authorization on account deletion
            'apple_refresh_token' => 'encrypted',
        ];
    }

    /**
     * The user's own time zone (set from the browser), else the app's. Reminder times and
     * "today" are computed in it.
     */
    public function localTimezone(): string
    {
        // ALL_WITH_BC: browsers still report old names such as "Asia/Calcutta" or "Europe/Kiev".
        return $this->timezone && in_array($this->timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
            ? $this->timezone
            : config('app.timezone');
    }

    /**
     * Push subscriptions a notification goes to: not the ones the push service reported as
     * expired (404 / 410, BRAI-154) — those stay listed in Profile until removed or pruned.
     * Overrides HasPushSubscriptions::routeNotificationForWebPush().
     */
    public function routeNotificationForWebPush(): Collection
    {
        return $this->pushSubscriptions()->whereNull('expired_at')->get();
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
     * Verification can be switched off in the instance admin settings.
     */
    public function hasVerifiedEmail(): bool
    {
        return ! InstanceSettings::requiresEmailVerification() || parent::hasVerifiedEmail();
    }

    /**
     * Suspended by an instance admin: can't sign in, tokens revoked.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
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
     * Signed-in desktop / mobile app installs (App API v1).
     */
    public function appDevices(): HasMany
    {
        return $this->hasMany(AppDevice::class);
    }

    /**
     * Personal API keys of the Developer API (v1.52.0).
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
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
     * Get the user's role in a specific project — only while they are a member of its workspace (Codex review,
     * finding 6): a project membership left over after the workspace removed them grants nothing.
     */
    public function projectRole(Project $project): ?string
    {
        return $this->currentProjects()
            ->where('project_id', $project->id)
            ->first()?->pivot->role;
    }

    /**
     * The user's project memberships in workspaces they still belong to. Project members are always workspace
     * members (there is no project-only role); Workspace::removeUser drops both, `memberships:prune-orphans`
     * cleans up rows from before that.
     */
    private function currentProjects(): BelongsToMany
    {
        return $this->projects()->whereIn('projects.workspace_id', DB::table('workspace_user')->select('workspace_id')->where('user_id', $this->id));
    }

    /**
     * Check if user has access to a workspace.
     */
    public function hasWorkspaceAccess(Workspace $workspace): bool
    {
        // a personal API key of this user narrows it to the key's scope (Developer API; never widens)
        if (($key = ApiKeyAccess::forUser($this)) !== null && ! $key->allowsWorkspace((int) $workspace->id)) {
            return false;
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
        if (($key = ApiKeyAccess::forUser($this)) !== null && ! $key->allowsProject((int) $project->id, (int) $project->workspace_id)) {
            return false;
        }

        // Workspace admins have access to all projects
        $workspaceRole = $this->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Check direct project assignment (in a workspace they are still a member of)
        return $this->currentProjects()
            ->where('project_id', $project->id)
            ->exists();
    }

    /**
     * IDs of projects the user can open: a project member, or owner/admin of its workspace.
     *
     * @return array<int, int>
     */
    public function accessibleProjectIds(): array
    {
        $ids = Project::query()
            ->whereIn('workspace_id', $this->workspaces()->select('workspaces.id'))
            ->where(fn ($q) => $q
                ->whereHas('workspace.users', fn ($uq) => $uq->where('users.id', $this->id)->whereIn('workspace_user.role', ['owner', 'admin']))
                ->orWhereHas('users', fn ($uq) => $uq->where('users.id', $this->id)))
            ->pluck('id')
            ->all();

        // every list built on this (My Work, search, captures' destinations, work logs …) follows an API key's scope
        $key = ApiKeyAccess::forUser($this);

        return $key === null ? $ids : $key->filterProjectIds($ids);
    }

    /**
     * IDs of projects the user administers: project admin, or admin/owner of the workspace.
     *
     * @return array<int, int>
     */
    public function adminProjectIds(): array
    {
        $workspaceIds = $this->workspaces()
            ->wherePivot('role', 'admin')
            ->pluck('workspaces.id')
            ->merge(Workspace::where('owner_id', $this->id)->pluck('id'));

        $ids = Project::whereIn('workspace_id', $workspaceIds)
            ->pluck('id')
            ->merge($this->currentProjects()->wherePivot('role', 'admin')->pluck('projects.id'))
            ->unique()
            ->values()
            ->all();

        $key = ApiKeyAccess::forUser($this);

        return $key === null ? $ids : $key->filterProjectIds($ids);
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
