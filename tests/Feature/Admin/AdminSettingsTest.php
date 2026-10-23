<?php

namespace Tests\Feature\Admin;

use App\Models\InstanceSettings;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Database\Seeders\InstanceSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
    }

    /**
     * @param  array<string, mixed>  $mail
     * @return array<string, mixed>
     */
    private function payload(array $mail = [], bool $registration = true, bool $verification = true): array
    {
        return [
            'allow_registration' => $registration,
            'require_email_verification' => $verification,
            'mail' => $mail + [
                'enabled' => false,
                'host' => null,
                'port' => null,
                'encryption' => 'tls',
                'username' => null,
                'password' => null,
                'from_address' => null,
                'from_name' => null,
            ],
        ];
    }

    public function test_smtp_password_is_encrypted_kept_and_never_sent_to_the_browser(): void
    {
        $smtp = ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'username' => 'mailer', 'password' => 's3cret-pass', 'from_address' => 'noreply@example.com', 'from_name' => 'Brainitive'];

        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->payload($smtp))->assertSessionHas('success');

        $stored = InstanceSettings::instance()->fresh()->settings['mail']['password'];
        $this->assertNotSame('s3cret-pass', $stored);
        $this->assertSame('s3cret-pass', InstanceSettings::mailSettings()['password']);

        // An empty password field keeps the stored one.
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->payload(['password' => null] + $smtp));
        InstanceSettings::flushCache();
        $this->assertSame('s3cret-pass', InstanceSettings::mailSettings()['password']);

        $this->actingAs($this->admin)
            ->get(route('admin.settings.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings', false)
                ->where('settings.mail.has_password', true)
                ->missing('settings.mail.password'))
            ->assertDontSee('s3cret-pass');
    }

    public function test_saved_smtp_settings_drive_outgoing_mail(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->payload([
            'enabled' => true, 'host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl', 'from_address' => 'noreply@example.com',
        ]));

        app('mail.manager');

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('noreply@example.com', config('mail.from.address'));
    }

    public function test_the_test_email_reports_the_result(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.settings.test-email'), ['to' => 'ops@example.com'])
            ->assertSessionHas('success');
    }

    public function test_closed_registration_only_admits_invited_emails(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->payload(registration: false));
        auth()->logout();
        InstanceSettings::flushCache();

        $signUp = fn (string $email) => $this->post('/register', [
            'name' => 'Someone', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password',
        ]);

        $signUp('stranger@example.com')->assertSessionHasErrors('email');
        $this->assertGuest();

        WorkspaceInvitation::create([
            'workspace_id' => $this->workspace->id,
            'email' => 'invited@example.com',
            'role' => 'editor',
            'token' => str()->random(40),
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(30),
        ]);

        $signUp('invited@example.com');
        $this->assertAuthenticated();
        $invited = User::where('email', 'invited@example.com')->firstOrFail();

        // the invited workspace is joined once the address is proven (verification link)
        $this->assertFalse($invited->hasWorkspaceAccess($this->workspace));
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $invited->id, 'hash' => sha1($invited->email)], absolute: false));
        $this->assertTrue($invited->fresh()->hasWorkspaceAccess($this->workspace));
    }

    public function test_fresh_installs_require_email_verification(): void
    {
        $this->seed(InstanceSettingsSeeder::class);

        $this->assertTrue(InstanceSettings::requiresEmailVerification());
        $this->assertTrue(InstanceSettings::allowsRegistration());
    }

    public function test_email_verification_can_be_switched_off(): void
    {
        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified)->get(route('workspaces.index'))->assertRedirect(route('verification.notice'));

        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->payload(verification: false));
        InstanceSettings::flushCache();

        $this->actingAs($unverified->fresh())->get(route('workspaces.index'))->assertOk();
    }
}
