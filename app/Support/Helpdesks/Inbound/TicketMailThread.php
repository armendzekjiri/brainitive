<?php

namespace App\Support\Helpdesks\Inbound;

use App\Models\Helpdesk;
use App\Models\HelpdeskInboundEmail;
use App\Models\Ticket;
use App\Models\TicketEmailMessage;

/**
 * Email threading for tickets (v1.40.0, BRAI-79).
 *
 * In: a message belongs to a ticket when its In-Reply-To / References name a Message-ID of that
 * ticket's thread (ticket_email_messages, this helpdesk only), or — when a client dropped those
 * headers — when its subject carries the ticket's routing tag "[KEY #token]", whose token is an HMAC
 * of the ticket uuid with the app key (knowing a ticket key is not enough). A subject without a
 * valid token never threads. Either way only a participant may add to the ticket (isParticipant).
 *
 * Out: our Message-IDs are recorded, and References / In-Reply-To are built from the thread.
 */
final class TicketMailThread
{
    public const TAG_PATTERN = '/\[([A-Z][A-Z0-9_]*-[A-Z]*\d+) #([a-f0-9]{10})\]/';

    public static function token(Ticket $ticket): string
    {
        return substr(hash_hmac('sha256', 'ticket-mail|'.$ticket->uuid, (string) config('app.key')), 0, 10);
    }

    /** "[KEY #token]", appended to the subject of every mail we send about the ticket. */
    public static function subjectTag(Ticket $ticket): string
    {
        return '['.$ticket->key.' #'.self::token($ticket).']';
    }

    /** "Re: <subject> [KEY #token]" (the tag only once, no "Re: Re:"). */
    public static function replySubject(Ticket $ticket): string
    {
        $subject = trim(preg_replace(self::TAG_PATTERN, '', (string) $ticket->subject) ?? '');
        $subject = preg_replace('/^((re|aw|fwd?|wg)\s*:\s*)+/i', '', $subject) ?? $subject;

        return trim('Re: '.$subject.' '.self::subjectTag($ticket));
    }

    public static function find(Helpdesk $helpdesk, InboundMessage $message): ?Ticket
    {
        $ids = array_values(array_unique(array_filter([$message->inReplyTo, ...array_reverse($message->references)])));
        if ($ids !== []) {
            $hashes = array_map(fn (string $id) => HelpdeskInboundEmail::hashOf($id), array_slice($ids, 0, 50));
            $ticketId = TicketEmailMessage::query()
                ->join('tickets', 'tickets.id', '=', 'ticket_email_messages.ticket_id')
                ->where('tickets.helpdesk_id', $helpdesk->id)
                ->whereNull('tickets.deleted_at')
                ->whereIn('ticket_email_messages.message_hash', $hashes)
                ->orderByDesc('ticket_email_messages.id')
                ->value('ticket_email_messages.ticket_id');
            if ($ticketId) {
                return Ticket::find($ticketId);
            }
        }

        if (preg_match_all(self::TAG_PATTERN, $message->subject, $tags, PREG_SET_ORDER)) {
            foreach ($tags as [, $key, $token]) {
                $ticket = Ticket::where('helpdesk_id', $helpdesk->id)->where('key', $key)->first();
                if ($ticket && hash_equals(self::token($ticket), $token)) {
                    return $ticket;
                }
            }
        }

        return null;
    }

    /** The ticket's contact, or someone who was on Cc of the message that opened it. */
    public static function isParticipant(Ticket $ticket, string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        if (strtolower((string) $ticket->contact?->email) === $email) {
            return true;
        }

        return in_array($email, self::participants($ticket), true);
    }

    /** @return list<string> Cc addresses recorded on the ticket (lower-cased) */
    public static function participants(Ticket $ticket): array
    {
        return array_values(array_filter((array) (($ticket->metadata ?? [])['email']['participants'] ?? []), 'is_string'));
    }

    /**
     * Message-IDs of the thread before $ownId (the message being sent; all when null), oldest first:
     * References = the first one plus the last few (RFC 5322 allows trimming), In-Reply-To = the last.
     *
     * @return array{in_reply_to: ?string, references: list<string>}
     */
    public static function headersFor(Ticket $ticket, ?string $ownId = null): array
    {
        $query = TicketEmailMessage::where('ticket_id', $ticket->id);
        if ($ownId !== null) {
            $own = TicketEmailMessage::where('ticket_id', $ticket->id)->where('message_hash', HelpdeskInboundEmail::hashOf($ownId))->min('id');
            if ($own) {
                $query->where('id', '<', $own);
            }
        }
        $ids = $query->orderBy('id')->pluck('message_id')->values()->all();

        if (count($ids) > 10) {
            $ids = array_merge([$ids[0]], array_slice($ids, -9));
        }

        return ['in_reply_to' => $ids === [] ? null : end($ids), 'references' => $ids];
    }
}
