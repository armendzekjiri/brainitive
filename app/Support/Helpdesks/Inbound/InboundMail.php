<?php

namespace App\Support\Helpdesks\Inbound;

use App\Models\Helpdesk;
use App\Models\HelpdeskInboundEmail;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketContact;
use App\Models\TicketEmailMessage;
use App\Models\TicketReply;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketReplyNotification;
use App\Support\Events\DomainEvents;
use App\Support\Events\EventPayloads;
use App\Support\Helpdesks\Mail\TicketMailer;
use App\Support\Uploads;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one entry point for received helpdesk email (v1.40.0, BRAI-79), whatever the transport: the
 * IMAP poller (ticket 3) and the forwarding-address webhook (ticket 4) build an InboundMessage and
 * call receive(). Rules, in order:
 *
 * 1. Dedupe on (helpdesk, Message-ID): a message seen before returns DUPLICATE (a "failed" one is
 *    tried again). The dedupe row is inserted in the same transaction as the ticket / reply, so a
 *    crash or a concurrent delivery never creates a second one.
 * 2. Ignore auto-replies, bounces, mailing lists and our own mail (InboundFilters) → IGNORED.
 * 3. Thread: In-Reply-To / References matched to the ticket's Message-IDs, else the HMAC routing tag
 *    in the subject (TicketMailThread). The subject text alone never threads.
 * 4. A threaded message from someone who isn't the contact or a Cc of the ticket (or whose domain
 *    fails DMARC) → REJECTED. Knowing a ticket key or Message-ID doesn't let anyone inject replies.
 * 5. Threaded → a contact reply (quoted history stripped) → REPLY_ADDED; watchers are notified.
 *    Not threaded → a new ticket with a matched / new TicketContact → TICKET_CREATED (at most
 *    `new_tickets_per_sender_per_hour` per sender, the rest IGNORED: a loop guard).
 * 6. Body: sanitized HTML (SafeHtml) or the plain text as paragraphs. Attachments go through the
 *    public-upload rules (Uploads: safe stored names, no programs / scripts, size and count limits,
 *    none when the helpdesk hides attachments); refused ones are listed under the message.
 *
 * Never throws for a bad message: an unexpected error is reported, recorded as "failed" and
 * answered REJECTED. Temp attachment files are always deleted (kept ones are copied first).
 */
final class InboundMail
{
    public const RESULT_TICKET_CREATED = 'ticket_created';

    public const RESULT_REPLY_ADDED = 'reply_added';

    public const RESULT_DUPLICATE = 'duplicate';

    /** auto-replies, bounces, mailing lists, our own outgoing mail */
    public const RESULT_IGNORED = 'ignored';

    /** e.g. a reply to a ticket from someone who isn't on it */
    public const RESULT_REJECTED = 'rejected';

    /** Contact uploads per ticket, as on the portal. */
    private const MAX_CONTACT_FILES_PER_TICKET = 20;

    public static function receive(Helpdesk $helpdesk, InboundMessage $message): string
    {
        $stored = [];
        try {
            return self::handle($helpdesk, $message, $stored);
        } catch (Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk('public')->delete($path);
            }
            report($e);
            self::record($helpdesk, $message, HelpdeskInboundEmail::STATUS_FAILED, 'error');

            return self::RESULT_REJECTED;
        } finally {
            MimeParser::cleanup($message);
        }
    }

    /**
     * Record a message the transport refused before it could be parsed in full (e.g. too large),
     * so it shows in the mailbox log and is deduplicated. Returns REJECTED (or DUPLICATE).
     */
    public static function reject(Helpdesk $helpdesk, InboundMessage $message, string $reason, ?int $size = null): string
    {
        MimeParser::cleanup($message);
        if (self::seen($helpdesk, $message)) {
            return self::RESULT_DUPLICATE;
        }

        return self::record($helpdesk, $message, self::RESULT_REJECTED, $reason, $size) ? self::RESULT_REJECTED : self::RESULT_DUPLICATE;
    }

    /**
     * @param  list<string>  $stored  public-disk paths written so far (deleted again on failure)
     */
    private static function handle(Helpdesk $helpdesk, InboundMessage $message, array &$stored): string
    {
        if (self::seen($helpdesk, $message)) {
            return self::RESULT_DUPLICATE;
        }

        if (($reason = InboundFilters::ignoreReason($helpdesk, $message)) !== null) {
            return self::record($helpdesk, $message, self::RESULT_IGNORED, $reason) ? self::RESULT_IGNORED : self::RESULT_DUPLICATE;
        }

        $from = strtolower(trim($message->fromEmail));
        $ticket = TicketMailThread::find($helpdesk, $message);

        if ($ticket) {
            if (! TicketMailThread::isParticipant($ticket, $from)) {
                return self::record($helpdesk, $message, self::RESULT_REJECTED, 'not_participant', null, $ticket) ? self::RESULT_REJECTED : self::RESULT_DUPLICATE;
            }
            if (InboundFilters::failsDmarc($message)) {
                return self::record($helpdesk, $message, self::RESULT_REJECTED, 'dmarc_fail', null, $ticket) ? self::RESULT_REJECTED : self::RESULT_DUPLICATE;
            }

            return self::addReply($helpdesk, $ticket, $message, $stored);
        }

        $recent = HelpdeskInboundEmail::where('helpdesk_id', $helpdesk->id)
            ->where('from_email', $from)
            ->where('status', self::RESULT_TICKET_CREATED)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($recent >= (int) config('helpdesk_mail.new_tickets_per_sender_per_hour', 10)) {
            return self::record($helpdesk, $message, self::RESULT_IGNORED, 'rate_limited') ? self::RESULT_IGNORED : self::RESULT_DUPLICATE;
        }

        return self::createTicket($helpdesk, $message, $stored);
    }

    /** @param list<string> $stored */
    private static function createTicket(Helpdesk $helpdesk, InboundMessage $message, array &$stored): string
    {
        $project = $helpdesk->project;
        $from = strtolower(trim($message->fromEmail));
        $own = array_filter([TicketMailer::replyAddress($helpdesk), strtolower((string) config('mail.from.address'))]);
        $participants = array_slice(array_values(array_diff($message->to, [$from], $own)), 0, 20);
        $subject = trim($message->subject) !== '' ? Str::limit(trim($message->subject), 250, '') : '(no subject)';

        try {
            [$ticket, $contact] = DB::transaction(function () use ($helpdesk, $project, $message, $from, $participants, $subject, &$stored) {
                $inbound = self::newRow($helpdesk, $message, self::RESULT_TICKET_CREATED);

                $contact = TicketContact::whereRaw('lower(email) = ?', [$from])->first()
                    ?? TicketContact::create(['email' => $from, 'name' => $message->fromName]);
                if ($message->fromName && ! $contact->name) {
                    $contact->update(['name' => $message->fromName]);
                }

                $column = $helpdesk->columns()->where('slug', 'new')->first() ?? $helpdesk->columns()->orderBy('position')->first();
                $ticket = $helpdesk->tickets()->create([
                    'subject' => $subject,
                    'description' => EmailBody::html($message, stripQuotes: false),
                    'column_id' => $column->id,
                    'ticket_contact_id' => $contact->id,
                    'key' => $project->getNextTicketKey(),
                    'position' => (Ticket::where('column_id', $column->id)->max('position') ?? 0) + 1,
                    'metadata' => ['source' => 'email', 'email' => ['transport' => $message->transport, 'participants' => $participants]],
                ]);

                $refused = self::storeAttachments($helpdesk, $ticket, null, $message, $stored);
                if ($refused !== []) {
                    $ticket->update(['description' => $ticket->getRawOriginal('description').self::refusedNote($refused)]);
                }

                TicketEmailMessage::record($ticket, $message->messageId, 'in');
                $inbound->update(['ticket_id' => $ticket->id]);

                return [$ticket, $contact];
            });
        } catch (UniqueConstraintViolationException) {
            self::forget($stored);

            return self::RESULT_DUPLICATE;
        }
        $stored = []; // committed: the files belong to the ticket now

        self::afterCommit(fn () => self::ticketCreated($helpdesk, $ticket, $contact, $from));

        return self::RESULT_TICKET_CREATED;
    }

    private static function ticketCreated(Helpdesk $helpdesk, Ticket $ticket, TicketContact $contact, string $from): void
    {
        $project = $helpdesk->project;
        DomainEvents::emit($project->workspace_id, 'ticket.created', fn () => ['ticket' => EventPayloads::ticket($ticket), 'source' => 'email']); // webhooks (BRAI-104)

        // The customer's confirmation: a threaded auto-reply from the helpdesk address, or the usual
        // notification. A few per address and hour, like the public form (no mail cannon).
        if (! InboundFilters::isNoReply($from) && RateLimiter::attempt('ticket-confirmation:'.$helpdesk->id.':'.sha1($from), 3, fn () => true, 3600)) {
            if (! TicketMailer::queueAcknowledgment($ticket)) {
                $contact->notify(new TicketCreatedNotification($ticket));
            }
        }

        foreach ($project->admins()->get() as $admin) {
            $admin->notify(new TicketCreatedNotification($ticket, $contact));
        }
    }

    /** @param list<string> $stored */
    private static function addReply(Helpdesk $helpdesk, Ticket $ticket, InboundMessage $message, array &$stored): string
    {
        $from = strtolower(trim($message->fromEmail));

        try {
            $reply = DB::transaction(function () use ($helpdesk, $ticket, $message, $from, &$stored) {
                $inbound = self::newRow($helpdesk, $message, self::RESULT_REPLY_ADDED, $ticket);

                // the contact, or a Cc participant (a contact of their own)
                $author = strtolower((string) $ticket->contact?->email) === $from
                    ? $ticket->contact
                    : (TicketContact::whereRaw('lower(email) = ?', [$from])->first() ?? TicketContact::create(['email' => $from, 'name' => $message->fromName]));

                $body = EmailBody::html($message, stripQuotes: true);
                $reply = $ticket->replies()->create([
                    'ticket_contact_id' => $author->id,
                    'body' => $body !== '' ? $body : '<p>(empty message)</p>',
                    'is_internal_note' => false,
                    'email_message_id' => $message->messageId,
                ]);

                $refused = self::storeAttachments($helpdesk, $ticket, $reply, $message, $stored);
                if ($refused !== []) {
                    $reply->update(['body' => $reply->getRawOriginal('body').self::refusedNote($refused)]);
                }

                TicketEmailMessage::record($ticket, $message->messageId, 'in', $reply);
                $inbound->update(['ticket_reply_id' => $reply->id]);

                return $reply;
            });
        } catch (UniqueConstraintViolationException) {
            self::forget($stored);

            return self::RESULT_DUPLICATE;
        }
        $stored = [];

        self::afterCommit(function () use ($ticket, $reply) {
            foreach ($ticket->watchers()->get() as $watcher) {
                $watcher->notify(new TicketReplyNotification($ticket, $reply, $reply->contact));
            }

            DomainEvents::emit(EventPayloads::ticketWorkspaceId($ticket), 'ticket.replied', fn () => EventPayloads::ticketReply($ticket, $reply)); // webhooks (BRAI-104)
        });

        return self::RESULT_REPLY_ADDED;
    }

    /**
     * Keep the attachments the public upload rules allow; returns "name (why)" for the others.
     *
     * @param  list<string>  $stored
     * @return list<string>
     */
    private static function storeAttachments(Helpdesk $helpdesk, Ticket $ticket, ?TicketReply $reply, InboundMessage $message, array &$stored): array
    {
        $refused = [];
        if ($message->attachments === []) {
            return $refused;
        }
        if (! empty(($helpdesk->settings ?? [])['default_field_attachments']['hidden'])) {
            return array_map(fn (array $a) => $a['filename'].' (this helpdesk does not accept attachments)', $message->attachments);
        }

        $maxBytes = (int) config('helpdesk_mail.max_attachment_bytes');
        $room = min((int) config('helpdesk_mail.max_attachments'),
            self::MAX_CONTACT_FILES_PER_TICKET - TicketAttachment::where('ticket_id', $ticket->id)->where('uploaded_by_contact', true)->count());

        foreach ($message->attachments as $attachment) {
            if (! is_file($attachment['path'])) {
                continue;
            }
            $file = new UploadedFile($attachment['path'], $attachment['filename'], $attachment['mime'], null, true);
            if ($attachment['size'] > $maxBytes) {
                $refused[] = Uploads::displayName($file).' (too large)';

                continue;
            }
            if (Uploads::isExecutable($file)) {
                $refused[] = Uploads::displayName($file).' (programs and scripts are not accepted)';

                continue;
            }
            if ($room <= 0) {
                $refused[] = Uploads::displayName($file).' (too many attachments)';

                continue;
            }

            $name = Uploads::name($file);
            $path = Storage::disk('public')->putFileAs('ticket-attachments/'.$ticket->id, $file, $name);
            if ($path === false) {
                continue;
            }
            $stored[] = $path;
            $room--;

            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'ticket_reply_id' => $reply?->id,
                'filename' => $name,
                'original_filename' => Uploads::displayName($file),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $attachment['size'],
                'uploaded_by_contact' => true,
            ]);
        }

        return $refused;
    }

    /** @param list<string> $refused */
    private static function refusedNote(array $refused): string
    {
        return '<p><em>Attachments not accepted: '.e(implode(', ', $refused)).'</em></p>';
    }

    private static function seen(Helpdesk $helpdesk, InboundMessage $message): bool
    {
        $existing = HelpdeskInboundEmail::where('helpdesk_id', $helpdesk->id)
            ->where('message_hash', HelpdeskInboundEmail::hashOf($message->messageId))
            ->first();

        if ($existing && $existing->status === HelpdeskInboundEmail::STATUS_FAILED) {
            $existing->delete();   // an earlier attempt crashed: try again

            return false;
        }

        return $existing !== null;
    }

    /** Insert the dedupe row (throws UniqueConstraintViolationException when it exists). */
    private static function newRow(Helpdesk $helpdesk, InboundMessage $message, string $status, ?Ticket $ticket = null, ?string $reason = null, ?int $size = null): HelpdeskInboundEmail
    {
        return HelpdeskInboundEmail::create([
            'helpdesk_id' => $helpdesk->id,
            'transport' => $message->transport,
            'message_hash' => HelpdeskInboundEmail::hashOf($message->messageId),
            'message_id' => mb_substr($message->messageId, 0, 2000),
            'from_email' => mb_substr(strtolower(trim($message->fromEmail)), 0, 255) ?: null,
            'subject' => Str::limit(trim($message->subject), 250, '') ?: null,
            'status' => $status,
            'reason' => $reason,
            'ticket_id' => $ticket?->id,
            'size' => $size,
        ]);
    }

    /** Record a message that changes nothing else; false when it was already recorded. */
    private static function record(Helpdesk $helpdesk, InboundMessage $message, string $status, ?string $reason, ?int $size = null, ?Ticket $ticket = null): bool
    {
        try {
            DB::transaction(fn () => self::newRow($helpdesk, $message, $status, $ticket, $reason, $size));

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** Notifications and events after the ticket / reply exists: a failure there is reported, never undoes it. */
    private static function afterCommit(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param list<string> $stored */
    private static function forget(array &$stored): void
    {
        foreach ($stored as $path) {
            Storage::disk('public')->delete($path);
        }
        $stored = [];
    }
}
