<?php

namespace Tests\Feature\Helpdesk;

use App\Models\AutomationRule;
use App\Models\HelpdeskInboundEmail;
use App\Models\Item;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketContact;
use App\Models\TicketEmailMessage;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketReplyNotification;
use App\Support\Helpdesks\Inbound\InboundMail;
use App\Support\Helpdesks\Inbound\InboundMessage;
use App\Support\Helpdesks\Inbound\TicketMailThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * InboundMail::receive() (BRAI-79): new tickets, threading, participants, loops, dedupe, bodies and
 * attachments, fed with the .eml fixtures in tests/Fixtures/mail.
 */
class InboundMailTest extends TestCase
{
    use InteractsWithHelpdeskMail;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHelpdeskMail();
        Notification::fake();
        Storage::fake('public');
    }

    private function newTicket(): Ticket
    {
        $this->assertSame(InboundMail::RESULT_TICKET_CREATED, $this->receive($this->eml('new-ticket')));

        return Ticket::where('helpdesk_id', $this->helpdesk->id)->latest('id')->firstOrFail();
    }

    public function test_a_new_mail_becomes_a_ticket_with_a_contact_and_a_threaded_acknowledgment(): void
    {
        $ticket = $this->newTicket();

        $this->assertSame('Printer says “no”', $ticket->subject);
        $this->assertSame('cleo@customer.example', $ticket->contact->email);
        $this->assertSame('Cleo Customer', $ticket->contact->name);
        $this->assertStringContainsString('Grüße from München!', $ticket->description);
        $this->assertStringNotContainsString('<script', $ticket->description);
        $this->assertStringContainsString('&lt;script&gt;', $ticket->description);
        $this->assertSame('email', $ticket->metadata['source']);
        $this->assertSame($this->helpdesk->columns()->orderBy('position')->first()->id, $ticket->column_id);

        $row = HelpdeskInboundEmail::firstOrFail();
        $this->assertSame([InboundMail::RESULT_TICKET_CREATED, $ticket->id, 'imap'], [$row->status, $row->ticket_id, $row->transport]);

        // the customer gets a threaded auto-reply from the helpdesk address instead of the generic notification
        $mail = $this->sentMail();
        $this->assertCount(1, $mail);
        $headers = $mail[0]->getHeaders();
        $this->assertSame('auto-replied', $headers->getHeaderBody('Auto-Submitted'));
        $this->assertSame(['CAF1234.new-ticket@mail.customer.example'], $headers->get('In-Reply-To')->getIds());
        $this->assertSame('support@helpdesk.example', $mail[0]->getReplyTo()[0]->getAddress());
        $this->assertSame('app@brainitive.test', $mail[0]->getFrom()[0]->getAddress());
        $this->assertSame('cleo@customer.example', $mail[0]->getTo()[0]->getAddress());
        $this->assertStringContainsString(TicketMailThread::subjectTag($ticket), $mail[0]->getSubject());
        $this->assertSame('helpdesk', $headers->getHeaderBody('X-Brainitive-Loop'));
        $this->assertSame(2, TicketEmailMessage::where('ticket_id', $ticket->id)->count());   // theirs + our acknowledgment

        Notification::assertNotSentTo($ticket->contact, TicketCreatedNotification::class);
        Notification::assertSentTo($this->admin, TicketCreatedNotification::class);
    }

    public function test_an_existing_contact_is_matched_case_insensitively(): void
    {
        $contact = TicketContact::create(['email' => 'CLEO@customer.example', 'name' => 'Cleo']);

        $ticket = $this->newTicket();

        $this->assertSame($contact->id, $ticket->ticket_contact_id);
        $this->assertSame(1, TicketContact::count());
    }

    public function test_an_emailed_ticket_reaches_the_automation_rules(): void
    {
        AutomationRule::create([
            'project_id' => $this->project->id, 'name' => 'Email to issue', 'enabled' => true, 'version' => 1,
            'actor_id' => $this->editor->id, 'trigger' => 'ticket.created', 'trigger_config' => [], 'conditions' => [],
            'actions' => [['type' => 'create_issue', 'value' => $this->board->id, 'text' => null]],
        ]);

        $this->newTicket();

        $this->assertSame('Printer says “no”', Item::where('board_id', $this->board->id)->sole()->title);
    }

    public function test_the_same_message_twice_creates_one_ticket(): void
    {
        $this->newTicket();

        $this->assertSame(InboundMail::RESULT_DUPLICATE, $this->receive($this->eml('new-ticket')));
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, HelpdeskInboundEmail::count());
    }

    public function test_a_reply_to_our_mail_threads_into_the_ticket_without_the_quoted_history(): void
    {
        $ticket = $this->newTicket();
        $ticket->addWatcher($this->editor);
        $ours = TicketEmailMessage::where('ticket_id', $ticket->id)->where('direction', 'out')->value('message_id');

        $result = $this->receive($this->eml('reply-quoted', ['IN-REPLY-TO' => $ours, 'REFERENCES' => 'CAF1234.new-ticket@mail.customer.example']));

        $this->assertSame(InboundMail::RESULT_REPLY_ADDED, $result);
        $this->assertSame(1, Ticket::count());
        $reply = $ticket->replies()->firstOrFail();
        $this->assertStringContainsString('restarting it helped', $reply->body);
        $this->assertStringNotContainsString('Please restart the printer', $reply->body);
        $this->assertSame($ticket->ticket_contact_id, $reply->ticket_contact_id);
        $this->assertFalse($reply->is_internal_note);
        $this->assertSame('reply.1@mail.customer.example', $reply->email_message_id);
        Notification::assertSentTo($this->editor, TicketReplyNotification::class);
    }

    public function test_references_to_the_customers_first_message_thread_too(): void
    {
        $ticket = $this->newTicket();

        $result = $this->receive($this->eml('reply-quoted', ['IN-REPLY-TO' => 'unknown@elsewhere.example', 'REFERENCES' => 'CAF1234.new-ticket@mail.customer.example']));

        $this->assertSame(InboundMail::RESULT_REPLY_ADDED, $result);
        $this->assertSame(1, $ticket->replies()->count());
    }

    public function test_the_subject_routing_tag_threads_only_with_a_valid_token(): void
    {
        $ticket = $this->newTicket();

        // a forged tag (right key, wrong token) starts a new ticket instead
        $forged = '['.$ticket->key.' #0000000000]';
        $this->assertSame(InboundMail::RESULT_TICKET_CREATED, $this->receive(str_replace('reply.outlook@', 'forged.outlook@', $this->eml('reply-outlook', ['SUBJECT-TAG' => $forged]))));
        $this->assertSame(0, $ticket->replies()->count());

        // the real tag threads even without In-Reply-To / References (HTML reply, Outlook quote cut off)
        $this->assertSame(InboundMail::RESULT_REPLY_ADDED, $this->receive($this->eml('reply-outlook', ['SUBJECT-TAG' => TicketMailThread::subjectTag($ticket)])));
        $reply = $ticket->replies()->firstOrFail();
        $this->assertStringContainsString('It broke again.', $reply->body);
        $this->assertStringNotContainsString('Please restart', $reply->body);
        $this->assertStringNotContainsString('color:red', $reply->body);
    }

    public function test_an_outsider_cannot_reply_into_a_ticket_but_a_cc_participant_can(): void
    {
        $ticket = $this->newTicket();
        $ours = TicketEmailMessage::where('ticket_id', $ticket->id)->where('direction', 'out')->value('message_id');

        $this->assertSame(InboundMail::RESULT_REJECTED, $this->receive($this->eml('outsider-reply', ['IN-REPLY-TO' => $ours])));
        $this->assertSame(0, $ticket->replies()->count());
        $this->assertSame('not_participant', HelpdeskInboundEmail::where('from_email', 'mallory@evil.example')->value('reason'));

        // Dana's ticket had Boss on Cc: Boss may answer in it
        $this->assertSame(InboundMail::RESULT_TICKET_CREATED, $this->receive($this->eml('multipart-attachments')));
        $dana = Ticket::latest('id')->firstOrFail();
        $this->assertSame(['boss@customer.example'], TicketMailThread::participants($dana));
        $reply = str_replace(['mallory@evil.example', 'forged.1@'], ['boss@customer.example', 'boss.1@'], $this->eml('outsider-reply', ['IN-REPLY-TO' => 'multi.4711@mail.customer.example']));
        $this->assertSame(InboundMail::RESULT_REPLY_ADDED, $this->receive($reply));
        $this->assertSame('boss@customer.example', $dana->replies()->firstOrFail()->contact->email);
    }

    public function test_a_reply_whose_domain_fails_dmarc_is_rejected(): void
    {
        $ticket = $this->newTicket();
        $ours = TicketEmailMessage::where('ticket_id', $ticket->id)->where('direction', 'out')->value('message_id');
        $raw = "Authentication-Results: mx.helpdesk.example; dmarc=fail (p=reject) header.from=customer.example\n"
            .$this->eml('reply-quoted', ['IN-REPLY-TO' => $ours, 'REFERENCES' => $ours]);

        $this->assertSame(InboundMail::RESULT_REJECTED, $this->receive($raw));
        $this->assertSame('dmarc_fail', HelpdeskInboundEmail::latest('id')->value('reason'));
    }

    public function test_auto_replies_bounces_lists_and_our_own_mail_are_ignored(): void
    {
        $this->assertSame(InboundMail::RESULT_IGNORED, $this->receive($this->eml('auto-reply')));
        $this->assertSame(InboundMail::RESULT_IGNORED, $this->receive($this->eml('bounce')));
        $this->assertSame(InboundMail::RESULT_IGNORED, $this->receive($this->eml('mailing-list')));
        // a copy of our own mail (the loop header, or from the helpdesk address)
        $this->assertSame(InboundMail::RESULT_IGNORED, $this->receive("X-Brainitive-Loop: helpdesk\n".str_replace('CAF1234.new-ticket', 'loop1', $this->eml('new-ticket'))));
        $this->assertSame(InboundMail::RESULT_IGNORED, $this->receive(str_replace(['Cleo Customer <Cleo@Customer.example>', 'CAF1234.new-ticket'], ['support@helpdesk.example', 'loop2'], $this->eml('new-ticket'))));

        $this->assertSame(0, Ticket::count());
        $this->assertEqualsCanonicalizing(
            ['auto_submitted', 'bounce', 'bulk', 'own_message', 'own_message'],
            HelpdeskInboundEmail::pluck('reason')->all(),
        );
        $this->assertSame([], $this->sentMail());
    }

    public function test_the_gmail_forwarding_confirmation_arrives_as_a_ticket_without_an_acknowledgment(): void
    {
        $raw = "Auto-Submitted: auto-generated\n".str_replace('Cleo Customer <Cleo@Customer.example>', 'Gmail Team <forwarding-noreply@google.com>', $this->eml('new-ticket'));

        $this->assertSame(InboundMail::RESULT_TICKET_CREATED, $this->receive($raw));
        $this->assertSame([], $this->sentMail());
    }

    public function test_new_tickets_per_sender_are_capped_per_hour(): void
    {
        config(['helpdesk_mail.new_tickets_per_sender_per_hour' => 2]);

        foreach ([1, 2, 3] as $i) {
            $results[] = $this->receive(str_replace('CAF1234.new-ticket', "flood{$i}", $this->eml('new-ticket')));
        }

        $this->assertSame([InboundMail::RESULT_TICKET_CREATED, InboundMail::RESULT_TICKET_CREATED, InboundMail::RESULT_IGNORED], $results);
        $this->assertSame('rate_limited', HelpdeskInboundEmail::latest('id')->value('reason'));
    }

    public function test_html_is_sanitized_and_only_safe_attachments_are_kept(): void
    {
        $this->assertSame(InboundMail::RESULT_TICKET_CREATED, $this->receive($this->eml('multipart-attachments')));
        $ticket = Ticket::firstOrFail();

        $this->assertStringContainsString('<b>attached</b>', $ticket->description);
        foreach (['onerror', '<script', 'javascript:'] as $bad) {
            $this->assertStringNotContainsString($bad, $ticket->description);
        }
        $this->assertStringContainsString('update.exe (programs and scripts are not accepted)', $ticket->description);
        $this->assertStringContainsString('page.html (programs and scripts are not accepted)', $ticket->description);

        $attachment = TicketAttachment::sole();
        $this->assertSame('invoice.pdf', $attachment->original_filename);
        $this->assertTrue($attachment->uploaded_by_contact);
        $this->assertMatchesRegularExpression('/^ticket-attachments\/\d+\/[0-9a-f-]{36}\.pdf$/', $attachment->path);
        Storage::disk('public')->assertExists($attachment->path);
    }

    public function test_attachments_respect_the_size_limit_and_the_helpdesk_setting(): void
    {
        config(['helpdesk_mail.max_attachment_bytes' => 10]);
        $this->receive($this->eml('multipart-attachments'));
        $this->assertSame(0, TicketAttachment::count());
        $this->assertStringContainsString('invoice.pdf (too large)', Ticket::firstOrFail()->description);

        config(['helpdesk_mail.max_attachment_bytes' => 10 * 1024 * 1024]);
        $this->helpdesk->update(['settings' => ['default_field_attachments' => ['hidden' => true]]]);
        $this->receive(str_replace('multi.4711', 'multi.4712', $this->eml('multipart-attachments')));
        $this->assertSame(0, TicketAttachment::count());
        $this->assertStringContainsString('does not accept attachments', Ticket::latest('id')->firstOrFail()->description);
    }

    public function test_temp_files_are_removed_and_a_failed_message_can_be_tried_again(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'inbound-test-');
        file_put_contents($path, 'hello');
        $message = fn (array $attachments) => new InboundMessage(
            'forwarding', 'retry-1@customer.example', 'cleo@customer.example', 'Cleo', ['support@helpdesk.example'],
            'Hello', 'Text', null, null, [], $attachments, [], now()->toImmutable(),
        );

        // a broken project makes the first attempt fail: recorded as "failed", answered REJECTED
        $projectId = $this->helpdesk->project_id;
        $this->helpdesk->setRelation('project', null);
        $this->assertSame(InboundMail::RESULT_REJECTED, InboundMail::receive($this->helpdesk, $message([['filename' => 'a.txt', 'mime' => 'text/plain', 'size' => 5, 'path' => $path]])));
        $this->assertFileDoesNotExist($path);
        $this->assertSame(HelpdeskInboundEmail::STATUS_FAILED, HelpdeskInboundEmail::sole()->status);

        // the next delivery of the same message is processed, not dropped as a duplicate
        $this->helpdesk->unsetRelation('project');
        $this->assertSame($projectId, $this->helpdesk->project->id);
        $this->assertSame(InboundMail::RESULT_TICKET_CREATED, InboundMail::receive($this->helpdesk, $message([])));
        $this->assertSame(InboundMail::RESULT_TICKET_CREATED, HelpdeskInboundEmail::sole()->status);
    }
}
