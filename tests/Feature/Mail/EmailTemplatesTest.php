<?php

namespace Tests\Feature\Mail;

use App\Mail\BillingReportMail;
use App\Models\BillingReport;
use App\Models\Board;
use App\Models\ClientDeliverable;
use App\Models\ClientPortal;
use App\Models\Comment;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\PortalIdentity;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\TicketReply;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ClientPortalGuestNotification;
use App\Notifications\ItemCommentedNotification;
use App\Notifications\Messages\BrandedMailMessage;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketReplyNotification;
use App\Support\Helpdesks\Mail\TicketMailer;
use App\Support\Mail\EmailPreviews;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BRAI-155: every email the app sends renders on the branded Brainitive layout. EmailPreviews is
 * the inventory (docs/ai/tasks/feat-email-templates.md); a new email belongs there too.
 */
class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://brainitive.test';

    protected function setUp(): void
    {
        parent::setUp();

        // the framework default name must never reach a recipient
        config(['app.url' => self::APP_URL, 'app.name' => 'Laravel']);
        URL::forceRootUrl(self::APP_URL);
        URL::forceScheme('https');
    }

    /** @return array<string, array{string}> */
    public static function emails(): array
    {
        return collect(EmailPreviews::list())->mapWithKeys(fn (array $e) => [$e['key'] => [$e['key']]])->all();
    }

    #[DataProvider('emails')]
    public function test_every_email_renders_on_the_branded_layout(string $key): void
    {
        $entry = collect(EmailPreviews::list())->firstWhere('key', $key);
        ['subject' => $subject, 'html' => $html, 'text' => $text] = EmailPreviews::render($key);

        $this->assertNotSame('', trim($subject));
        $this->assertStringNotContainsStringIgnoringCase('laravel', $subject);

        // layout, logo (absolute, light + dark), preheader, colour-scheme support
        $this->assertStringContainsString('data-brand="brainitive"', $html);
        $this->assertStringContainsString('src="'.self::APP_URL.'/images/email/logo.png"', $html);
        $this->assertStringContainsString('src="'.self::APP_URL.'/images/email/logo-dark.png"', $html);
        $this->assertStringContainsString('alt="Brainitive"', $html);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark"', $html);
        $this->assertStringContainsString('class="preheader"', $html);
        $this->assertStringContainsString('background-color: #F4F6F8', $html, 'theme CSS inlined');

        // no framework branding, no tracking pixels
        $this->assertStringNotContainsStringIgnoringCase('laravel', $html);
        $this->assertStringNotContainsStringIgnoringCase('laravel', $text);
        $this->assertStringNotContainsString('Regards,', $html);
        $this->assertStringNotContainsString('Hello!', $html);
        $this->assertSame(2, substr_count($html, '<img '), 'only the two logo variants');

        // every link absolute https
        preg_match_all('/href="([^"]*)"/', $html, $links);
        $this->assertNotEmpty($links[1]);
        foreach ($links[1] as $link) {
            $this->assertStringStartsWith('https://', html_entity_decode($link), "{$key}: {$link}");
        }

        // members can manage their notifications; customers, clients and invitees never get that link
        if ($entry['group'] === 'member') {
            $this->assertStringContainsString('href="'.self::APP_URL.'/profile#notifications"', $html);
            $this->assertStringContainsString('Manage notifications: '.self::APP_URL.'/profile#notifications', $text);
        } else {
            $this->assertStringNotContainsString('Manage notifications', $html);
            $this->assertStringNotContainsString('/profile', $html);
        }
        $this->assertStringContainsString('You get this email because', $html);

        // a text part without markup
        $this->assertNotSame('', trim($text));
        $this->assertStringNotContainsString('<table', $text);
        $this->assertStringNotContainsString('<p', $text);
    }

    public function test_user_written_content_stays_escaped_and_cannot_add_links(): void
    {
        $evil = '<script>alert(1)</script> [Verify your account](https://evil.test/login) <img src=x onerror=alert(2)>';

        $item = $this->make(Item::class, ['id' => 1, 'key' => 'WEB-1', 'title' => $evil], ['board' => $this->board()]);
        $comment = $this->make(Comment::class, ['body' => $evil, 'parent_id' => null]);
        $actor = $this->make(User::class, ['name' => 'Mallory [click](https://evil.test)']);
        $mails = [
            (new ItemCommentedNotification($item, $comment, $actor))->toMail($this->user()),
            (new TicketCreatedNotification($this->ticket($evil)))->toMail($this->contact()),
            (new TicketReplyNotification($this->ticket($evil), $this->make(TicketReply::class, ['body' => $evil]), $actor))->toMail($this->contact()),
            (new ClientPortalGuestNotification(ClientPortalGuestNotification::REPLIED, $this->portal(), $this->deliverable($evil), $evil, $evil))->toMail($this->guest()),
        ];

        foreach ($mails as $mail) {
            $this->assertNoInjection((string) $mail->render());
        }

        $billing = new BillingReportMail($this->report(), 'Invoice', $evil, 'sam@example.com', 'Sam <b>Rivera</b>');
        $this->assertNoInjection((string) $billing->render());
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', (string) $billing->render());
    }

    public function test_quoted_text_cannot_break_out_of_its_panel_as_markdown(): void
    {
        // a customer reply that tries to close the quote panel with a code fence + blank lines
        $body = '<p>```</p><p>x</p><p></p><p></p><p># URGENT: your account is locked</p><p>**Reply with your password**</p><p>```</p><p>C:\\\\server\\share</p>';
        $html = (string) (new TicketReplyNotification($this->ticket('Help'), $this->make(TicketReply::class, ['body' => $body]), $this->contact()))
            ->toMail($this->user())->render();

        $this->assertStringNotContainsString('<h1>URGENT', $html);
        $this->assertStringNotContainsString('URGENT: your account is locked</h1>', $html);
        $this->assertStringNotContainsString('<strong', $html);
        $this->assertStringNotContainsString('<pre', $html);
        $this->assertStringNotContainsString('<code', $html);
        $this->assertStringContainsString('# URGENT: your account is locked', $html); // shown as typed, inside the quote
        $this->assertStringContainsString('class="button button-primary"', $html);
        $this->assertStringContainsString('C:\\\\server\\share', $html); // backslashes kept (not eaten by the secured encoding)

        // catalog bodies (comments) are plain text in the panel too
        $comment = $this->make(Comment::class, ['body' => '<p># Heading</p><p>- item **bold**</p>', 'parent_id' => null]);
        $item = $this->make(Item::class, ['id' => 1, 'key' => 'WEB-1', 'title' => 'T'], ['board' => $this->board()]);
        $html = (string) (new ItemCommentedNotification($item, $comment, $this->user()))->toMail($this->user())->render();
        $this->assertStringContainsString('# Heading - item **bold**', $html);
        $this->assertStringNotContainsString('<li', $html);
    }

    public function test_a_watcher_gets_the_member_footer_and_the_customer_the_helpdesk_footer(): void
    {
        $ticket = $this->ticket('Printer on fire');
        $reply = $this->make(TicketReply::class, ['body' => '<p>On it.</p>']);

        $team = (new TicketReplyNotification($ticket, $reply, $this->contact()))->toMail($this->user());
        $this->assertInstanceOf(BrandedMailMessage::class, $team);
        $this->assertSame(BrandedMailMessage::MEMBER, $team->audience);
        $this->assertStringContainsString('Manage notifications', (string) $team->render());

        $customer = (new TicketReplyNotification($ticket, $reply, $this->user()))->toMail($this->contact());
        $html = (string) $customer->render();
        $this->assertSame(BrandedMailMessage::GUEST, $customer->audience);
        $this->assertStringNotContainsString('Manage notifications', $html);
        $this->assertStringContainsString('Brainitive — Acme Support', $html); // who wrote, not "Laravel"
        $this->assertStringContainsString('— Acme Support', $html);
        $this->assertStringContainsString('Hi Dana,', $html);
        $this->assertStringContainsString($ticket->publicUrl(), html_entity_decode($html));
    }

    public function test_mailbox_replies_keep_the_agents_html_and_a_plain_text_part(): void
    {
        $email = TicketMailer::compose($this->ticket('Invoice question'), $this->make(TicketReply::class, ['body' => '<p>We fixed your <b>invoice</b>.</p>']));

        $this->assertStringContainsString('<b>invoice</b>', $email->getHtmlBody());
        $this->assertStringContainsString('data-brand="brainitive"', $email->getHtmlBody());
        $this->assertStringContainsString('Acme Support · SUP-1', $email->getHtmlBody());
        $this->assertStringStartsWith('We fixed your invoice.', $email->getTextBody()); // inbound threading reads this part

        // an agent's backslashes survive the Markdown renderer
        $email = TicketMailer::compose($this->ticket('Share'), $this->make(TicketReply::class, ['body' => '<p>Open \\\\server\\share and \\[x\\]</p>']));
        $this->assertStringContainsString('Open \\\\server\\share and \\[x\\]', $email->getHtmlBody());
    }

    public function test_the_password_reset_mail_is_branded_and_links_to_app_url(): void
    {
        $mail = (new ResetPassword('secret-token'))->toMail($this->user());

        $this->assertInstanceOf(BrandedMailMessage::class, $mail);
        $this->assertSame('Reset your Brainitive password', $mail->subject);
        $this->assertStringStartsWith(self::APP_URL.'/reset-password/secret-token?email=', $mail->actionUrl);
        $this->assertStringContainsString('data-brand="brainitive"', (string) $mail->render());
    }

    public function test_a_plain_mail_message_still_renders_branded(): void
    {
        $html = (string) (new MailMessage)->subject('Hi')->line('Something happened.')->action('Open', self::APP_URL.'/x')->render();

        $this->assertStringContainsString('data-brand="brainitive"', $html);
        $this->assertStringContainsString('Hi there,', $html);
        $this->assertStringContainsString('— Brainitive', $html);
        $this->assertStringNotContainsString('Manage notifications', $html);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private function assertNoInjection(string $html): void
    {
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('href="https://evil.test', $html);
        $this->assertStringNotContainsString('<b>Rivera</b>', $html);
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function make(string $class, array $attributes, array $relations = []): Model
    {
        $model = new $class;
        $model->forceFill($attributes);
        foreach ($relations as $name => $related) {
            $model->setRelation($name, $related);
        }

        return $model;
    }

    private function user(): User
    {
        return $this->make(User::class, ['id' => 1, 'name' => 'Alex Morgan', 'email' => 'alex@example.com']);
    }

    private function contact(): TicketContact
    {
        return $this->make(TicketContact::class, ['name' => 'Dana Customer', 'email' => 'dana@example.com']);
    }

    private function guest(): PortalIdentity
    {
        return $this->make(PortalIdentity::class, ['uuid' => (string) Str::uuid(), 'name' => 'Jordan', 'email' => 'jordan@example.com']);
    }

    private function project(): Project
    {
        $workspace = $this->make(Workspace::class, ['name' => 'Acme', 'slug' => 'acme']);

        return $this->make(Project::class, ['name' => 'Website', 'slug' => 'website', 'key' => 'WEB'], ['workspace' => $workspace]);
    }

    private function board(): Board
    {
        return $this->make(Board::class, ['name' => 'Main', 'slug' => 'main'], ['project' => $this->project()]);
    }

    private function ticket(string $subject): Ticket
    {
        $helpdesk = $this->make(Helpdesk::class, ['name' => 'Acme Support', 'slug' => 'support'], ['project' => $this->project(), 'mailbox' => null]);

        return $this->make(Ticket::class, ['id' => 1, 'uuid' => (string) Str::uuid(), 'key' => 'SUP-1', 'subject' => $subject], ['helpdesk' => $helpdesk, 'contact' => $this->contact()]);
    }

    private function portal(): ClientPortal
    {
        return $this->make(ClientPortal::class, ['title' => 'Acme portal', 'slug' => 'acme-portal'], ['project' => $this->project()]);
    }

    private function deliverable(string $title): ClientDeliverable
    {
        return $this->make(ClientDeliverable::class, ['uuid' => (string) Str::uuid(), 'title' => $title, 'version' => 1], ['portal' => $this->portal()]);
    }

    private function report(): BillingReport
    {
        return $this->make(BillingReport::class, ['workspace_id' => 0, 'status' => 'finalized', 'snapshot' => [
            'kind' => 'invoice', 'number' => 'INV-1', 'period' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
            'client' => ['name' => 'Muster GmbH'], 'issuer' => ['company_name' => 'Acme'],
        ]]);
    }
}
