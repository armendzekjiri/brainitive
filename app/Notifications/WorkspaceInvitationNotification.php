<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\Messages\BrandedMailMessage;
use App\Support\Mail\BrandedMail;
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

    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = route('invitations.show', ['token' => $this->invitation->token], true);
        $roleLabel = ucfirst($this->invitation->role);
        $workspace = BrandedMail::line($this->workspace->name, 80);
        $inviter = BrandedMail::line($this->inviter->name, 80);

        return BrandedMailMessage::account($notifiable, "You get this email because {$inviter} invited this address to {$workspace} on Brainitive.")
            ->greeting('Hi there,')
            ->subject("You're invited to join {$this->workspace->name} — Brainitive")
            ->eyebrow($workspace)
            ->headline("You're invited to join {$workspace}")
            ->preheader("{$inviter} invited you to {$workspace} as {$roleLabel}.")
            ->line(NewLeadNotification::plain($inviter).' has invited you to join **'.NewLeadNotification::plain($workspace).'** on Brainitive.')
            ->line("You'll join as **{$roleLabel}**. Accept the invitation to get started — create an account or sign in if you already have one.")
            ->action('Accept invitation', $acceptUrl)
            ->line("This invitation is valid for 30 days. If you didn't expect this email, you can safely ignore it.")
            ->context($workspace);
    }
}
