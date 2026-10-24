<?php

namespace App\Models;

use App\Support\Notifications\WorkspaceNotifications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WorkspaceInvitation extends Model
{
    protected $fillable = [
        'workspace_id',
        'email',
        'role',
        'token',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (WorkspaceInvitation $invitation) {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(64);
            }
            if (empty($invitation->expires_at)) {
                $invitation->expires_at = now()->addDays(30);
            }
        });
    }

    /**
     * Get the workspace for this invitation.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the user who sent this invitation.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Check if the invitation is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the invitation has been accepted.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Check if the invitation is valid (not expired and not accepted).
     */
    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isAccepted();
    }

    /**
     * Mark the invitation as accepted.
     */
    public function markAsAccepted(): void
    {
        $this->update(['accepted_at' => now()]);
    }

    /**
     * Scope to get only valid invitations.
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now())
            ->whereNull('accepted_at');
    }

    /**
     * Scope to get pending invitations for an email.
     */
    public function scopeForEmail($query, string $email)
    {
        return $query->where('email', $email);
    }

    /**
     * New accounts are allowed while registration is open, and always for an email with a
     * pending invitation.
     */
    public static function canSignUp(string $email): bool
    {
        return InstanceSettings::allowsRegistration()
            || self::query()->forEmail(strtolower($email))->valid()->exists();
    }

    /**
     * Accept all valid (non-expired, not yet accepted) workspace invitations for a user's email
     * and add the user to those workspaces. Called after registration.
     */
    public static function acceptPendingForUser(User $user): void
    {
        $email = strtolower($user->email);

        $invitations = self::query()
            ->forEmail($email)
            ->valid()
            ->with(['workspace', 'inviter'])
            ->get();

        foreach ($invitations as $invitation) {
            $invitation->markAsAccepted();
            // already a member (added directly meanwhile): the invitation is simply done
            if (! $invitation->workspace->users()->where('users.id', $user->id)->exists()) {
                $invitation->workspace->addUser($user, $invitation->role, $invitation->inviter ?? null);
                WorkspaceNotifications::invitationAccepted($invitation, $user);
            }
        }
    }
}
