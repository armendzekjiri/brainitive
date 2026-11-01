<?php

namespace App\Models;

use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
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

    /** Only the invited address may accept (case-insensitive). */
    public function emailMatches(User $user): bool
    {
        return strtolower($user->email) === strtolower($this->email);
    }

    /**
     * Accept for the user (web, App API, sign-up): blocked only when the workspace is over its
     * member limit (the open invitation is already counted). Someone who is already a member
     * (added directly meanwhile) just closes the invitation. Callers check expiry and email.
     *
     * @throws LimitReached
     */
    public function acceptBy(User $user): void
    {
        app(LimitGate::class)->ensureCanAddMembers($this->workspace, 0);

        $this->markAsAccepted();
        if (! $this->workspace->hasUser($user)) {
            $this->workspace->addUser($user, $this->role, $this->inviter ?? null);
            WorkspaceNotifications::invitationAccepted($this, $user);
        }
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
            // A workspace over its member limit (Cloud plans) keeps the invitation open: the link in
            // the email still works once the workspace is upgraded. Registration itself never fails.
            try {
                $invitation->acceptBy($user);
            } catch (LimitReached) {
                continue;
            }
        }
    }
}
