<?php

namespace Tests\Feature\Helpdesk;

use App\Jobs\ProcessInboundMail;
use App\Models\Helpdesk;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Helpdesks\Inbound\ForwardingAddress;
use App\Support\Helpdesks\Inbound\InboundMail;
use App\Support\Helpdesks\Inbound\InboundMessage;
use App\Support\Helpdesks\Inbound\InboundTempFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-124: provider webhooks for helpdesk forwarding addresses (Postmark + Mailgun). Mapping is
 * checked on the queued ProcessInboundMail job (Queue::fake), so these tests don't depend on the
 * shared pipeline (InboundMail::receive, ticket 3). Fixtures: tests/Fixtures/inbound/.
 */
class InboundMailWebhookTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private const MAILGUN_KEY = 'mg-signing-key';

    private const POSTMARK_TOKEN = 'pm-secret-token';

    private Helpdesk $helpdesk;

    private string $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->enable();
        $owner = User::find($this->workspace->owner_id);
        $this->helpdesk = Helpdesk::create(['project_id' => $this->project->id, 'name' => 'Support', 'slug' => 'support', 'created_by' => $owner->id]);
        $this->address = ForwardingAddress::for($this->helpdesk);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(InboundTempFiles::root());
        parent::tearDown();
    }

    private function enable(): void
    {
        config([
            'services.inbound_mail.domain' => 'inbound.example',
            'services.inbound_mail.provider' => 'postmark',
            'services.inbound_mail.postmark_token' => self::POSTMARK_TOKEN,
            'services.inbound_mail.mailgun_signing_key' => self::MAILGUN_KEY,
            'services.inbound_mail.replay_window' => 900,
        ]);
    }

    private function fixture(string $name, ?string $to = null): array
    {
        $json = File::get(base_path("tests/Fixtures/inbound/{$name}.json"));

        return json_decode(str_replace('{{TO}}', $to ?? $this->address, $json), true);
    }

    private function postmark(array $payload, ?string $token = self::POSTMARK_TOKEN): TestResponse
    {
        $headers = $token === null ? [] : ['Authorization' => 'Basic '.base64_encode('postmark:'.$token)];

        return $this->postJson(route('inbound-mail.postmark'), $payload, $headers);
    }

    /** @param  array<string, mixed>  $fields */
    private function mailgun(array $fields, ?int $timestamp = null, ?string $token = null, ?string $key = self::MAILGUN_KEY): TestResponse
    {
        $timestamp ??= time();
        $token ??= bin2hex(random_bytes(25));
        $fields += ['timestamp' => (string) $timestamp, 'token' => $token, 'signature' => hash_hmac('sha256', $timestamp.$token, (string) $key)];

        return $this->post(route('inbound-mail.mailgun'), $fields, ['Accept' => 'application/json']);
    }

    private function queuedMessage(): InboundMessage
    {
        $jobs = Queue::pushed(ProcessInboundMail::class);
        $this->assertCount(1, $jobs);
        $this->assertSame($this->helpdesk->id, $jobs->first()->helpdeskId);

        return $jobs->first()->message;
    }

    // ── disabled ──────────────────────────────────────────────────────────

    public function test_endpoints_answer_404_until_configured(): void
    {
        Queue::fake();
        config(['services.inbound_mail.domain' => null]);
        $this->postmark($this->fixture('postmark'))->assertNotFound();
        $this->mailgun($this->fixture('mailgun'))->assertNotFound();

        // a domain without that provider's secret is still off
        config(['services.inbound_mail.domain' => 'inbound.example', 'services.inbound_mail.mailgun_signing_key' => null]);
        $this->mailgun($this->fixture('mailgun'))->assertNotFound();
        $this->postmark($this->fixture('postmark'))->assertOk();
    }

    // ── Postmark ──────────────────────────────────────────────────────────

    public function test_postmark_payload_is_mapped_to_an_inbound_message(): void
    {
        Queue::fake();
        $this->postmark($this->fixture('postmark'))->assertOk()->assertJson(['message' => 'Accepted.']);

        $message = $this->queuedMessage();
        $this->assertSame('forwarding', $message->transport);
        $this->assertSame('CAB2wZ1Pz9Xyz@mail.customer.example', $message->messageId);
        $this->assertSame('cleo@customer.example', $message->fromEmail);
        $this->assertSame('Cleo Customer', $message->fromName);
        $this->assertSame([$this->address, 'jo@customer.example'], $message->to);
        $this->assertSame('Printer on fire', $message->subject);
        $this->assertSame("Hello,\n\nthe printer is on fire again.\n\nCleo", $message->text);
        $this->assertStringContainsString('<b>on fire</b>', $message->html);
        $this->assertSame('ticket-42.abc@brainitive.example', $message->inReplyTo);
        $this->assertSame(['first.1@brainitive.example', 'ticket-42.abc@brainitive.example'], $message->references);
        $this->assertSame('no', $message->headers['auto-submitted']);
        $this->assertSame('by mx1 with SMTP', $message->headers['received'], 'the first of a repeated header wins');

        // attachments: temp files under random names, display names made safe
        $this->assertCount(2, $message->attachments);
        [$png, $txt] = $message->attachments;
        $this->assertSame(['screenshot.png', 'image/png', 70], [$png['filename'], $png['mime'], $png['size']]);
        $this->assertSame(['notes.txt', 'text/plain'], [$txt['filename'], $txt['mime']]);
        $this->assertSame("smoke everywhere\n", File::get($txt['path']));
        $this->assertStringStartsWith(InboundTempFiles::root(), $txt['path']);
        $this->assertStringNotContainsString('notes', $txt['path']);
    }

    public function test_postmark_needs_the_token_by_basic_auth_or_query(): void
    {
        Queue::fake();
        $this->postmark($this->fixture('postmark'), null)->assertUnauthorized();
        $this->postmark($this->fixture('postmark'), 'wrong')->assertUnauthorized();
        Queue::assertNothingPushed();

        $this->postJson(route('inbound-mail.postmark', ['token' => self::POSTMARK_TOKEN]), $this->fixture('postmark'))->assertOk();
        Queue::assertPushed(ProcessInboundMail::class, 1);
    }

    public function test_unknown_recipient_is_refused_without_retry(): void
    {
        Queue::fake();
        $this->postmark($this->fixture('postmark', 'nobody-abc@inbound.example'))->assertForbidden();
        // our local part on someone else's domain is not ours
        $local = strtok($this->address, '@');
        $this->postmark($this->fixture('postmark', "{$local}@elsewhere.example"))->assertForbidden();
        $this->mailgun($this->fixture('mailgun', 'nobody-abc@inbound.example'))->assertStatus(406);
        Queue::assertNothingPushed();
        $this->assertSame([], File::isDirectory(InboundTempFiles::root()) ? File::directories(InboundTempFiles::root()) : []);
    }

    public function test_sub_addressed_recipient_reaches_the_helpdesk_and_stays_in_to(): void
    {
        Queue::fake();
        [$local, $domain] = explode('@', $this->address);
        $this->postmark($this->fixture('postmark', "{$local}+t9f8e7@{$domain}"))->assertOk();
        $this->assertContains("{$local}+t9f8e7@{$domain}", $this->queuedMessage()->to);
    }

    public function test_regenerated_address_replaces_the_old_one(): void
    {
        Queue::fake();
        $old = $this->address;
        $new = ForwardingAddress::regenerate($this->helpdesk);
        $this->assertNotSame($old, $new);

        $this->postmark($this->fixture('postmark', $old))->assertForbidden();
        $this->postmark($this->fixture('postmark', $new))->assertOk();
    }

    public function test_deleted_helpdesk_no_longer_receives(): void
    {
        Queue::fake();
        $this->helpdesk->delete();
        $this->postmark($this->fixture('postmark'))->assertForbidden();
    }

    public function test_message_without_a_sender_is_refused(): void
    {
        Queue::fake();
        $payload = $this->fixture('postmark');
        $payload['FromFull'] = ['Email' => '', 'Name' => ''];
        $payload['From'] = 'not an address';
        $this->postmark($payload)->assertForbidden()->assertJson(['message' => 'The message has no sender.']);
        Queue::assertNothingPushed();
    }

    public function test_odd_value_types_are_ignored_instead_of_crashing(): void
    {
        Queue::fake();
        $payload = $this->fixture('postmark');
        $payload['FromFull']['Name'] = ['not', 'a', 'string'];
        $payload['Subject'] = ['x'];
        $payload['TextBody'] = 42;
        $payload['Headers'][] = ['Name' => ['x'], 'Value' => ['y']];
        $payload['Attachments'][] = ['Name' => 'broken.bin', 'Content' => '%%% not base64 %%%'];
        $this->postmark($payload)->assertOk();

        $message = $this->queuedMessage();
        $this->assertNull($message->fromName);
        $this->assertSame('', $message->subject);
        $this->assertNull($message->text);
        $this->assertCount(2, $message->attachments);

        $this->mailgun(['message-headers' => '[[["x"], ["y"]], "junk"]'] + $this->fixture('mailgun'))->assertOk();
    }

    public function test_attachments_over_the_limits_are_dropped(): void
    {
        Queue::fake();
        config(['services.inbound_mail.max_attachment_kb' => 1]);
        $payload = $this->fixture('postmark');
        $payload['Attachments'][] = ['Name' => 'big.bin', 'Content' => base64_encode(str_repeat('x', 4096)), 'ContentType' => 'application/octet-stream'];
        $this->postmark($payload)->assertOk();
        $this->assertSame(['screenshot.png', 'notes.txt'], array_column($this->queuedMessage()->attachments, 'filename'));

        config(['services.inbound_mail.max_attachment_kb' => 10240, 'services.inbound_mail.max_attachments' => 1]);
        Queue::fake();
        $this->postmark($payload)->assertOk();
        $this->assertSame(['screenshot.png'], array_column($this->queuedMessage()->attachments, 'filename'));
    }

    public function test_message_without_message_id_gets_a_stable_id(): void
    {
        Queue::fake();
        $payload = $this->fixture('postmark');
        $payload['Headers'] = [];
        $this->postmark($payload)->assertOk();
        $this->assertSame('postmark-73e6d360-66eb-11e1-8e72-a8904824019b@inbound.invalid', $this->queuedMessage()->messageId);
    }

    // ── Mailgun ───────────────────────────────────────────────────────────

    public function test_mailgun_payload_is_mapped_to_an_inbound_message(): void
    {
        Queue::fake();
        $fields = $this->fixture('mailgun') + ['attachment-1' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.4 tiny')];
        $this->mailgun($fields)->assertOk();

        $message = $this->queuedMessage();
        $this->assertSame('CAB2wZ1Pz9Xyz@mail.customer.example', $message->messageId);
        // the From header, not the envelope sender of the forwarder
        $this->assertSame('cleo@customer.example', $message->fromEmail);
        $this->assertSame('Cleo Customer', $message->fromName);
        $this->assertSame([$this->address, 'support@customer-company.example', 'jo@customer.example'], $message->to);
        $this->assertSame('Printer on fire', $message->subject);
        $this->assertSame("Hello,\n\nthe printer is on fire again.\n\nCleo", $message->text);
        $this->assertSame('ticket-42.abc@brainitive.example', $message->inReplyTo);
        $this->assertSame(['first.1@brainitive.example', 'ticket-42.abc@brainitive.example'], $message->references);
        $this->assertSame('bulk', $message->headers['precedence']);
        $this->assertSame('<news.customer.example>', $message->headers['list-id']);

        $this->assertCount(1, $message->attachments);
        $this->assertSame('report.pdf', $message->attachments[0]['filename']);
        $this->assertSame('%PDF-1.4 tiny', File::get($message->attachments[0]['path']));
    }

    public function test_mailgun_signature_must_match(): void
    {
        Queue::fake();
        $this->mailgun($this->fixture('mailgun'), key: 'another-key')->assertUnauthorized();
        $fields = $this->fixture('mailgun');
        $this->post(route('inbound-mail.mailgun'), $fields, ['Accept' => 'application/json'])->assertUnauthorized();
        Queue::assertNothingPushed();
    }

    public function test_mailgun_refuses_stale_and_replayed_signatures(): void
    {
        Queue::fake();
        $this->mailgun($this->fixture('mailgun'), timestamp: time() - 3600)->assertStatus(406)->assertJson(['message' => 'Signature expired.']);
        $this->mailgun($this->fixture('mailgun'), timestamp: time() + 3600)->assertStatus(406);

        $token = bin2hex(random_bytes(25));
        $timestamp = time();
        $this->mailgun($this->fixture('mailgun'), $timestamp, $token)->assertOk();
        $this->mailgun($this->fixture('mailgun'), $timestamp, $token)->assertStatus(406)->assertJson(['message' => 'Already received.']);
        Queue::assertPushed(ProcessInboundMail::class, 1);
    }

    public function test_mailgun_falls_back_to_the_envelope_sender(): void
    {
        Queue::fake();
        $fields = $this->fixture('mailgun');
        unset($fields['from'], $fields['From']);
        $fields['message-headers'] = '[]';
        $this->mailgun($fields)->assertOk();
        $this->assertSame('bounce+srs=abc@forwarder.example', $this->queuedMessage()->fromEmail);

        $fields['sender'] = '';
        $this->mailgun($fields)->assertStatus(406);
    }

    // ── the queued job ────────────────────────────────────────────────────

    public function test_the_job_hands_the_message_to_the_pipeline_and_removes_temp_files(): void
    {
        // QUEUE_CONNECTION=sync in tests: the job runs inside the request
        Log::spy();
        $this->postmark($this->fixture('postmark'))->assertOk();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'Inbound mail received'
            && $context['helpdesk'] === $this->helpdesk->id && in_array($context['result'], [
                InboundMail::RESULT_TICKET_CREATED, InboundMail::RESULT_REPLY_ADDED, InboundMail::RESULT_DUPLICATE, InboundMail::RESULT_IGNORED, InboundMail::RESULT_REJECTED,
            ], true))->once();
        $this->assertSame([], File::directories(InboundTempFiles::root()), 'temp attachments are removed after receive()');
    }

    /**
     * End to end once ticket 3's InboundMail::receive() is merged: a forwarded mail becomes a
     * ticket. Skipped while receive() is the foundation stub (always "ignored").
     */
    public function test_forwarded_mail_becomes_a_ticket_through_the_pipeline(): void
    {
        $probe = new InboundMessage('forwarding', 'probe@x', 'probe@customer.example', null, [], 'probe', 'probe', null, null, [], [], [], now()->toImmutable());
        $method = new ReflectionMethod(InboundMail::class, 'receive');
        $stub = $method->getEndLine() - $method->getStartLine() <= 3;
        if ($stub && InboundMail::receive($this->helpdesk, $probe) === InboundMail::RESULT_IGNORED) {
            $this->markTestSkipped('InboundMail::receive() is still the foundation stub (ticket 3).');
        }

        $payload = $this->fixture('postmark');
        $payload['Headers'] = [['Name' => 'Message-ID', 'Value' => '<new-ticket-1@mail.customer.example>']];
        $this->postmark($payload)->assertOk();

        $ticket = Ticket::where('helpdesk_id', $this->helpdesk->id)->where('subject', 'Printer on fire')->first();
        $this->assertNotNull($ticket, 'the forwarded mail created a ticket');
        $this->assertSame('cleo@customer.example', $ticket->contact->email);

        // the provider retries: still one ticket
        $this->postmark($payload)->assertOk();
        $this->assertSame(1, Ticket::where('helpdesk_id', $this->helpdesk->id)->where('subject', 'Printer on fire')->count());
    }
}
