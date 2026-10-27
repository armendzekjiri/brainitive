<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Support\Mail\EmailPreviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/** Instance admin → Emails (BRAI-155): previews from sample data + a test send to yourself. */
class AdminEmailPreviewTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
    }

    /** @return list<SentMessage> */
    private function sent(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    public function test_only_instance_admins_reach_the_previews(): void
    {
        $member = $this->member();
        $key = EmailPreviews::list()[0]['key'];

        $this->actingAs($member)->get(route('admin.emails.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.emails.show', $key))->assertForbidden();
        $this->actingAs($member)->post(route('admin.emails.send', $key))->assertForbidden();
        $this->assertSame([], $this->sent());

        auth()->logout();
        $this->get(route('admin.emails.index'))->assertRedirect(route('login'));
        $this->get(route('admin.emails.show', $key))->assertRedirect(route('login'));
    }

    public function test_the_page_lists_every_email(): void
    {
        $this->actingAs($this->admin)->get(route('admin.emails.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Emails')
                ->has('emails', count(EmailPreviews::entries()))
                ->where('adminEmail', $this->admin->email)
                ->has('groups', 3));
    }

    public function test_every_preview_renders_framed_by_the_app_only_and_saves_nothing(): void
    {
        $tables = ['users', 'tickets', 'ticket_contacts', 'items', 'workspaces', 'notifications', 'portal_identities'];
        $before = array_map(fn ($t) => DB::table($t)->count(), $tables);

        foreach (EmailPreviews::list() as $email) {
            $response = $this->actingAs($this->admin)->get(route('admin.emails.show', $email['key']));
            $response->assertOk()
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
            $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
            $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('data-brand="brainitive"', $response->getContent(), $email['key']);
            // sample people only: no real account of this instance shows up
            $this->assertStringNotContainsString($this->admin->email, $response->getContent());
            $this->assertStringNotContainsString($this->owner->email, $response->getContent());

            $this->actingAs($this->admin)->getJson(route('admin.emails.show', [$email['key'], 'format' => 'json']))
                ->assertOk()->assertJsonStructure(['subject', 'text']);
        }

        $this->assertSame($before, array_map(fn ($t) => DB::table($t)->count(), $tables));
        $this->assertSame([], $this->sent());
    }

    public function test_the_dark_toggle_forces_the_dark_rules_whatever_the_browser(): void
    {
        $dark = $this->actingAs($this->admin)->get(route('admin.emails.show', ['verify-email', 'scheme' => 'dark']))->getContent();
        $light = $this->actingAs($this->admin)->get(route('admin.emails.show', ['verify-email', 'scheme' => 'light']))->getContent();
        $auto = $this->actingAs($this->admin)->get(route('admin.emails.show', 'verify-email'))->getContent();

        $this->assertStringContainsString('@media all {', $dark);
        $this->assertStringNotContainsString('prefers-color-scheme: dark', $dark);
        $this->assertStringContainsString('@media not all {', $light);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $auto);
    }

    public function test_unknown_previews_are_404(): void
    {
        $this->actingAs($this->admin)->get(route('admin.emails.show', 'nope'))->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.emails.send', 'nope'))->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/emails/..%2Fsettings')->assertNotFound();
    }

    public function test_send_test_goes_to_the_admin_only_and_is_throttled(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.emails.send', 'ticket-reply-customer'), ['to' => 'someone-else@example.com'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $message = $sent[0]->getOriginalMessage();
        $this->assertSame([$this->admin->email], array_map(fn ($a) => $a->getAddress(), $message->getTo()));
        $this->assertSame([], $message->getCc());
        $this->assertStringStartsWith('[Test] Reply on ticket SUP-12', $message->getSubject());
        $this->assertStringContainsString('data-brand="brainitive"', $message->getHtmlBody());
        $this->assertStringContainsString('Northwind Support', $message->getTextBody());
        $this->assertTrue(AdminAuditLog::where('action', 'emails.test_sent')->exists());

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($this->admin)->post(route('admin.emails.send', 'verify-email'))->assertRedirect();
        }
        $this->actingAs($this->admin)->post(route('admin.emails.send', 'verify-email'))->assertStatus(429);
        // (each send re-applies the instance mail config, which starts a fresh array transport)
        $this->assertSame('[Test] Verify your email — Brainitive', last($this->sent())->getOriginalMessage()->getSubject());
    }

    public function test_the_settings_test_email_is_branded(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.test-email'), ['to' => 'ops@example.com'])
            ->assertSessionHas('success');

        $message = $this->sent()[0]->getOriginalMessage();
        $this->assertSame('Brainitive test email', $message->getSubject());
        $this->assertStringContainsString('data-brand="brainitive"', $message->getHtmlBody());
        $this->assertStringContainsString('outgoing mail works', $message->getTextBody());
    }
}
