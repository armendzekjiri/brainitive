<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Messages\BrandedMailMessage;
use App\Support\Booking\BookingText;
use App\Support\CalendarFeed\Ics;
use App\Support\Legal\PublicOperator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The guest's emails for a booking (BRAI-158): confirmed / rescheduled (with an .ics and the manage
 * link), cancelled, and the reminder. Mail only; the guest has no account. Times in the guest's zone.
 */
class BookingGuestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const KINDS = ['confirmed', 'rescheduled', 'cancelled', 'reminder'];

    /** $token: the plain manage token (only for confirmed / rescheduled / reminder links). */
    public function __construct(public readonly Booking $booking, public readonly string $kind, public readonly ?string $token = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type = $this->booking->type;
        $page = $type->page;
        $host = $page->user;
        $zone = $this->booking->guest_timezone ?: $page->timezone;
        $when = BookingText::when($this->booking, $zone);
        $name = LeadConfirmationNotification::safeName($this->booking->guest_name);
        $typeName = NewLeadNotification::plain($type->name);
        $hostName = NewLeadNotification::plain($host->name);

        [$subject, $headline] = match ($this->kind) {
            'rescheduled' => ["Moved: {$typeName} with {$hostName}", 'Your booking was moved'],
            'cancelled' => ["Cancelled: {$typeName} with {$hostName}", 'Your booking was cancelled'],
            'reminder' => ["Tomorrow: {$typeName} with {$hostName}", 'See you soon'],
            default => ["Booked: {$typeName} with {$hostName}", 'You’re booked'],
        };

        $mail = BrandedMailMessage::guest($notifiable, "You get this email because you booked a time on {$hostName}’s booking page.")
            ->subject($subject)
            ->eyebrow($typeName)
            ->headline($headline)
            ->greeting("Hi {$name},")
            ->line($when)
            ->context(NewLeadNotification::plain($page->title))
            ->signOff($hostName);

        if ($this->kind === 'cancelled') {
            $mail->line($this->booking->cancelled_by === 'host' ? "{$hostName} cancelled this booking." : 'You cancelled this booking.');
            $mail->quote($this->booking->cancelled_by === 'host' ? $this->booking->cancel_reason : null);
            $mail->action('Book another time', route('booking.public', $page->public_slug));
        } else {
            $location = $this->booking->conference_url ? null : BookingText::location($type);
            if ($location !== null) {
                $mail->line('Where: '.NewLeadNotification::plain($location));
            }
            if ($this->booking->conference_url) {
                $mail->action('Join the call', $this->booking->conference_url);
            }
            if ($this->token !== null) {
                $mail->footerLink('Reschedule or cancel', route('booking.manage', $this->token));
            }
            if ($this->kind !== 'reminder') {
                $mail->attachData($this->ics(), 'booking.ics', ['mime' => 'text/calendar; charset=UTF-8; method=PUBLISH']);
            }
        }

        // who runs the page (BRAI-156): the workspace, not the platform
        return $mail->line(PublicOperator::mailLine($page->workspace, nameFallback: true));
    }

    private function ics(): string
    {
        $type = $this->booking->type;

        return Ics::calendar($type->name, [[
            'uid' => $this->booking->uuid.'@brainitive',
            'stamp' => now(),
            'summary' => $type->name.' with '.$type->page->user->name,
            'all_day' => false,
            'start' => $this->booking->starts_at,
            'end' => $this->booking->ends_at,
            'description' => $this->booking->conference_url ? 'Join: '.$this->booking->conference_url : BookingText::location($type),
            'url' => $this->booking->conference_url,
        ]]);
    }
}
