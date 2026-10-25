<?php

namespace Tests\Feature\Helpdesk;

use App\Models\HelpdeskInboundEmail;
use App\Models\HelpdeskMailbox;
use App\Models\Ticket;
use App\Support\Helpdesks\Inbound\InboundMail;
use App\Support\Helpdesks\Mail\FakeMailboxConnector;
use App\Support\Helpdesks\Mail\MailboxConnector;
use App\Support\Helpdesks\Mail\MailboxPoller;
use App\Support\Helpdesks\Mail\MailHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Helpdesk settings → Email → Connected mailbox (BRAI-79): permissions, write-only encrypted
 * passwords, host / port guards, "Test connection", and the poller (fake IMAP, no network).
 */
class HelpdeskMailboxTest extends TestCase
{
    use InteractsWithHelpdeskMail;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHelpdeskMail();
        Notification::fake();
    }

    private function url(string $name = 'show'): string
    {
        return route('helpdesks.mailbox.'.$name, [$this->workspace->slug, $this->project->slug, $this->helpdesk->slug]);
    }

    /** Public IP literals: no DNS lookups in tests. */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'enabled' => true,
            'email_address' => 'Help@Example.com',
            'imap_host' => '93.184.216.34',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'help@example.com',
            'imap_password' => 'new-imap-secret',
            'imap_folder' => 'INBOX',
            'processed_folder' => null,
            'smtp_mode' => 'app',
        ];
    }

    public function test_an_editor_saves_the_mailbox_and_passwords_never_come_back(): void
    {
        $response = $this->actingAs($this->editor)->putJson($this->url('update'), $this->form())->assertOk();

        $response->assertJsonPath('mailbox.email_address', 'help@example.com')
            ->assertJsonPath('mailbox.has_imap_password', true)
            ->assertJsonMissingPath('mailbox.imap_password');
        $this->assertStringNotContainsString('new-imap-secret', $response->getContent());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));

        // encrypted at rest
        $raw = DB::table('helpdesk_mailboxes')->where('helpdesk_id', $this->helpdesk->id)->value('imap_password');
        $this->assertNotSame('new-imap-secret', $raw);
        $this->assertSame('new-imap-secret', $this->mailbox->fresh()->imap_password);

        // an empty password keeps the saved one; SMTP fields are required only in SMTP mode
        $this->actingAs($this->editor)->putJson($this->url('update'), $this->form(['imap_password' => null, 'from_name' => 'Help team']))->assertOk();
        $this->assertSame('new-imap-secret', $this->mailbox->fresh()->imap_password);
        $this->actingAs($this->editor)->putJson($this->url('update'), $this->form(['smtp_mode' => 'smtp']))
            ->assertJsonValidationErrors(['smtp_host', 'smtp_port', 'smtp_encryption']);

        $this->actingAs($this->editor)->getJson($this->url())->assertOk()->assertJsonMissingPath('mailbox.smtp_password');
        $this->assertStringNotContainsString('imap_password', json_encode($this->helpdesk->fresh()->load('mailbox')->toArray()));
    }

    public function test_viewers_and_outsiders_cannot_see_or_change_the_mailbox(): void
    {
        foreach ([$this->viewer, $this->outsider] as $user) {
            $this->actingAs($user)->getJson($this->url())->assertForbidden();
            $this->actingAs($user)->putJson($this->url('update'), $this->form())->assertForbidden();
            $this->actingAs($user)->postJson($this->url('test'), $this->form())->assertForbidden();
            $this->actingAs($user)->deleteJson($this->url('destroy'))->assertForbidden();
        }
        $this->assertSame('imap-secret', $this->mailbox->fresh()->imap_password);
    }

    public function test_private_hosts_other_ports_and_unencrypted_connections_are_refused(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '[::1]'] as $host) {
            $this->actingAs($this->editor)->putJson($this->url('update'), $this->form(['imap_host' => $host]))
                ->assertJsonValidationErrors('imap_host');
        }
        $this->actingAs($this->editor)->putJson($this->url('update'), $this->form(['imap_port' => 22]))->assertJsonValidationErrors('imap_port');
        $this->actingAs($this->editor)->putJson($this->url('update'), $this->form(['imap_encryption' => 'none']))->assertJsonValidationErrors('imap_encryption');
        $this->actingAs($this->editor)->putJson($this->url('update'), $this->form(['smtp_mode' => 'smtp', 'smtp_host' => '192.168.1.1', 'smtp_port' => 587, 'smtp_encryption' => 'starttls']))
            ->assertJsonValidationErrors('smtp_host');

        $this->assertFalse(MailHost::allowPrivate());
        $this->assertNotNull(MailHost::problem('localhost', 993, 'imap'));
        $this->assertNull(MailHost::problem('93.184.216.34', 993, 'imap'));
    }

    public function test_test_connection_reports_success_and_failure_without_saving(): void
    {
        $this->app->instance(MailboxConnector::class, new FakeMailboxConnector([1 => $this->eml('new-ticket')]));

        $this->actingAs($this->editor)->postJson($this->url('test'), $this->form())
            ->assertOk()->assertJson(['ok' => true, 'message' => 'Connected to INBOX: 1 unread message.']);

        $this->actingAs($this->editor)->postJson($this->url('test'), $this->form(['imap_password' => 'wrong-password']))
            ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('message', fn ($m) => str_contains($m, 'Login failed'));

        // an empty password field tests with the saved one
        $this->actingAs($this->editor)->postJson($this->url('test'), $this->form(['imap_password' => null]))->assertJsonPath('ok', true);
        $this->assertSame('imap.helpdesk.example', $this->mailbox->fresh()->imap_host);
    }

    public function test_the_poller_imports_unseen_mail_once_and_marks_it(): void
    {
        $fake = new FakeMailboxConnector(
            [1 => $this->eml('new-ticket'), 2 => $this->eml('auto-reply'), 3 => $this->eml('mailing-list')],
            [3 => 50 * 1024 * 1024],
        );
        $this->app->instance(MailboxConnector::class, $fake);

        $this->artisan('app:poll-helpdesk-mailboxes')->assertSuccessful();

        $this->assertSame([1, 2, 3], $fake->processed);
        $this->assertSame(1, Ticket::count());
        $this->assertEqualsCanonicalizing(
            [InboundMail::RESULT_TICKET_CREATED, InboundMail::RESULT_IGNORED, InboundMail::RESULT_REJECTED],
            HelpdeskInboundEmail::pluck('status')->all(),
        );
        $tooLarge = HelpdeskInboundEmail::where('reason', 'too_large')->sole();
        $this->assertSame('This week in printers', $tooLarge->subject);   // the header was read, not the body
        $mailbox = $this->mailbox->fresh();
        $this->assertNotNull($mailbox->last_success_at);
        $this->assertNull($mailbox->last_error);

        // a crash before \Seen: the same mail shows up again → recognised, only marked
        $fake->messages[4] = $this->eml('new-ticket');
        $this->artisan('app:poll-helpdesk-mailboxes')->assertSuccessful();
        $this->assertSame([1, 2, 3, 4], $fake->processed);
        $this->assertSame(1, Ticket::count());
    }

    public function test_connection_errors_are_recorded_and_back_off(): void
    {
        $this->app->instance(MailboxConnector::class, new FakeMailboxConnector(failWith: "Can't connect to imap.helpdesk.example:993 (Connection timed out)."));

        $this->artisan('app:poll-helpdesk-mailboxes')->assertSuccessful();

        $mailbox = $this->mailbox->fresh();
        $this->assertStringContainsString('Connection timed out', $mailbox->last_error);
        $this->assertSame(1, $mailbox->consecutive_failures);
        $this->assertFalse(MailboxPoller::due($mailbox));
        $this->travel(3)->minutes();
        $this->assertTrue(MailboxPoller::due($mailbox->fresh()));
    }

    public function test_disabled_mailboxes_and_deleted_helpdesks_are_not_polled(): void
    {
        $fake = new FakeMailboxConnector([1 => $this->eml('new-ticket')]);
        $this->app->instance(MailboxConnector::class, $fake);

        $this->mailbox->update(['enabled' => false]);
        $this->artisan('app:poll-helpdesk-mailboxes')->assertSuccessful();
        $this->mailbox->update(['enabled' => true]);
        $this->helpdesk->delete();
        $this->artisan('app:poll-helpdesk-mailboxes')->assertSuccessful();

        $this->assertSame(0, $fake->opened);
    }

    public function test_disconnecting_removes_the_mailbox(): void
    {
        $this->actingAs($this->editor)->deleteJson($this->url('destroy'))->assertOk()->assertJsonPath('mailbox', null);
        $this->assertSame(0, HelpdeskMailbox::count());
    }
}
