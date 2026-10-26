<?php

namespace App\Mail;

use App\Models\BillingReport;
use App\Support\Billing\BillingDocument;
use App\Support\Mail\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * A finalized billing report (time report or invoice) mailed to the client (v1.38.0, BRAI-101), with
 * the PDF attached. From the app's mail-from; replies go to the admin who sent it. The PDF is
 * rendered in the queue worker from the frozen snapshot, so it is the document that was finalized.
 */
class BillingReportMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public BillingReport $report,
        public string $subjectLine,
        public ?string $body,
        public string $replyToEmail,
        public string $replyToName,
    ) {}

    public function envelope(): Envelope
    {
        // the app's mail-from address, but named after the admin, so it never looks like a platform mail
        return new Envelope(
            from: new Address((string) config('mail.from.address'), Str::limit($this->replyToName, 60, '').' via '.config('app.name')),
            subject: $this->subjectLine,
            replyTo: [new Address($this->replyToEmail, $this->replyToName)],
        );
    }

    public function content(): Content
    {
        $snapshot = (array) $this->report->snapshot;

        return new Content(
            markdown: 'emails.billing-report', // the branded layout (BRAI-155)
            text: 'emails.billing-report-text',
            with: [
                'body' => $this->body,
                'filename' => BillingDocument::fromReport($this->report)->filename('pdf'),
                // one line each: they are echoed into Markdown paragraphs (eyebrow, sign-off)
                'company' => BrandedMail::line((string) data_get($snapshot, 'issuer.company_name', ''), 120),
                'sender' => BrandedMail::line($this->replyToName, 120),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $document = BillingDocument::fromReport($this->report);

        return [
            Attachment::fromData(fn () => $document->pdf(), $document->filename('pdf'))->withMime('application/pdf'),
        ];
    }
}
