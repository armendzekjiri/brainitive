<?php

namespace Tests\Feature\Helpdesk;

use App\Models\HelpdeskMailbox;
use App\Models\Ticket;
use App\Models\TicketEmailMessage;
use App\Models\TicketReply;
use App\Notifications\TicketReplyNotification;
use App\Support\Helpdesks\Inbound\InboundMail;
use App\Support\Helpdesks\Inbound\TicketMailThread;
use App\Support\Helpdesks\Mail\MailTransports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Agent replies on a helpdesk with a connected mailbox go out as threaded email (BRAI-79):
 * stable Message-ID, In-Reply-To / References, Cc participants, no duplicate notification,
 * internal notes never send, failures visible and retryable.
 */
class TicketReplyEmailTest extends TestCase
{
    use InteractsWithHelpdeskMail;
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHelpdeskMail();
        Notification::fake();

        $this->assertSame(InboundMail::RESULT_TICKET_CREATED, $this->receive($this->eml('multipart-attachments')));
        $this->ticket = Ticket::firstOrFail();
        $this->clearSentMail();
    }

    private function reply(array $data): TestResponse
    {
        return $this->actingAs($this->admin)->post(
            route('tickets.replies.store', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug, $this->ticket->id]),
            $data,
        );
    }

    public function test_a_public_reply_is_emailed_in_the_thread_instead_of_the_notification(): void
    {
        $this->reply(['body' => '<p>We fixed your <b>invoice</b>.</p>'])->assertRedirect();

        $reply = TicketReply::where('user_id', $this->admin->id)->sole();
        $this->assertSame('sent', $reply->email_status);
        $this->assertNotNull($reply->email_sent_at);
        $this->assertStringEndsWith('@helpdesk.example', $reply->email_message_id);

        $mail = $this->sentMail();
        $this->assertCount(1, $mail);
        /** @var Email $email */
        $email = $mail[0];
        $headers = $email->getHeaders();
        $this->assertSame([$reply->email_message_id], $headers->get('Message-ID')->getIds());
        $thread = TicketEmailMessage::where('ticket_id', $this->ticket->id)->orderBy('id')->pluck('message_id')->all();
        // customer's mail, our acknowledgment, this reply
        $this->assertCount(3, $thread);
        $this->assertSame([$thread[1]], $headers->get('In-Reply-To')->getIds());
        $this->assertSame([$thread[0], $thread[1]], $headers->get('References')->getIds());
        $this->assertSame('dana@customer.example', $email->getTo()[0]->getAddress());
        $this->assertSame(['boss@customer.example'], array_map(fn ($a) => $a->getAddress(), $email->getCc()));
        $this->assertSame('Re: Invoice question '.TicketMailThread::subjectTag($this->ticket), $email->getSubject());
        $this->assertStringContainsString('<b>invoice</b>', $email->getHtmlBody());
        $this->assertStringContainsString('We fixed your invoice.', $email->getTextBody());
        $this->assertNull($headers->get('Auto-Submitted'));

        Notification::assertNotSentTo($this->ticket->contact, TicketReplyNotification::class);

        // the customer answers that mail: it lands in the same ticket
        $answer = str_replace(['Cleo Customer <cleo@customer.example>', 'reply.1@'], ['dana@customer.example', 'dana.2@'], $this->eml('reply-quoted', ['IN-REPLY-TO' => $reply->email_message_id, 'REFERENCES' => $thread[0]]));
        $this->assertSame(InboundMail::RESULT_REPLY_ADDED, $this->receive($answer));
        $this->assertSame(2, $this->ticket->replies()->count());
    }

    public function test_internal_notes_never_send(): void
    {
        $this->reply(['body' => 'Customer is grumpy', 'is_internal_note' => true])->assertRedirect();

        $this->assertSame([], $this->sentMail());
        $this->assertNull(TicketReply::sole()->email_status);
        Notification::assertNotSentTo($this->ticket->contact, TicketReplyNotification::class);
    }

    public function test_without_a_connected_mailbox_the_contact_gets_the_usual_notification(): void
    {
        $this->mailbox->update(['enabled' => false]);

        $this->reply(['body' => 'Hello'])->assertRedirect();

        $this->assertSame([], $this->sentMail());
        $this->assertNull(TicketReply::where('user_id', $this->admin->id)->sole()->email_message_id);
        Notification::assertSentTo($this->ticket->contact, TicketReplyNotification::class);
    }

    public function test_a_failed_send_is_visible_and_can_be_retried_with_the_same_message_id(): void
    {
        $this->mailbox->update(['smtp_mode' => 'smtp', 'smtp_host' => 'smtp.helpdesk.example', 'smtp_port' => 587, 'smtp_encryption' => 'starttls']);
        $transport = new class extends ArrayTransport
        {
            public bool $fail = true;

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                if ($this->fail) {
                    throw new TransportException('Connection to smtp.helpdesk.example:587 timed out.');
                }

                return parent::send($message, $envelope);
            }
        };
        $this->app->instance(MailTransports::class, new class($transport) extends MailTransports
        {
            public function __construct(private TransportInterface $transport) {}

            public function forMailbox(HelpdeskMailbox $mailbox): TransportInterface
            {
                return $mailbox->smtp_mode === 'smtp' ? $this->transport : parent::forMailbox($mailbox);
            }
        });

        $this->reply(['body' => 'Try this'])->assertRedirect();
        $reply = TicketReply::where('user_id', $this->admin->id)->sole();
        $this->assertSame('failed', $reply->email_status);
        $this->assertStringContainsString('timed out', $reply->email_error);
        $messageId = $reply->email_message_id;

        // a viewer can't retry
        $retry = route('tickets.replies.resend-email', [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug, $this->ticket->id, $reply->id]);
        $this->actingAs($this->viewer)->post($retry)->assertForbidden();

        $transport->fail = false;
        $this->actingAs($this->admin)->post($retry)->assertRedirect()->assertSessionHas('success');

        $reply->refresh();
        $this->assertSame('sent', $reply->email_status);
        $this->assertSame($messageId, $reply->email_message_id);
        $this->assertSame([$messageId], $transport->messages()->first()->getOriginalMessage()->getHeaders()->get('Message-ID')->getIds());
        $this->assertSame('support@helpdesk.example', $transport->messages()->first()->getOriginalMessage()->getFrom()[0]->getAddress());
    }
}
