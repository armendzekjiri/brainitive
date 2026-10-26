<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Admin console → Settings → "Send test email" (BRAI-155: on the branded layout, was Mail::raw).
 * Sent right away, never queued, so the admin sees SMTP errors at once.
 */
class InstanceTestMail extends Mailable
{
    public function __construct(public readonly string $transport) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Brainitive test email');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.instance-test', with: ['transport' => $this->transport]);
    }
}
