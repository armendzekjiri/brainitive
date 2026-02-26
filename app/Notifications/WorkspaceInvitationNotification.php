<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WorkspaceInvitation $invitation,
        public Workspace $workspace,
        public User $inviter
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = route('invitations.accept', ['token' => $this->invitation->token], true);
        $appName = config('app.name');
        $roleLabel = ucfirst($this->invitation->role);

        return (new MailMessage)
            ->subject("You're invited to join {$this->workspace->name} — {$appName}")
            ->view('emails.workspace-invitation', [
                'invitation' => $this->invitation,
                'workspace' => $this->workspace,
                'inviter' => $this->inviter,
                'acceptUrl' => $acceptUrl,
                'appName' => $appName,
                'roleLabel' => $roleLabel,
            ]);
    }
}
