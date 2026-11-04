<?php

namespace Tests\Feature\AppApi;

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `GET /api/v1/instance` (BRAI-354): public instance info for the desktop / mobile first-run screen. */
class InstanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_answers_without_a_token_with_the_documented_shape(): void
    {
        config([
            'app.version' => '1.49.0',
            'app.url' => 'https://brainitive.example/',
            'app-api.min_versions' => ['desktop' => '0.2.0', 'mobile' => '0.3.0'],
            'services.google.client_id' => null,
            'services.github.client_id' => null,
            'services.microsoft.client_id' => null,
            'services.apple.client_id' => null,
        ]);
        InstanceSettings::instance()->update(['name' => 'Acme Brain']);

        $response = $this->get('/api/v1/instance')->assertOk();

        $response->assertExactJson(['data' => [
            'name' => 'Acme Brain',
            'url' => 'https://brainitive.example',
            'version' => '1.49.0',
            'api_version' => '1',
            'sign_in' => ['password' => true, 'google' => false, 'github' => false, 'microsoft' => false, 'apple' => false],
            'sign_up_open' => true,
            'email_verification_required' => true,
            'min_versions' => ['desktop' => '0.2.0', 'mobile' => '0.3.0'],
            'authorize_path' => '/app/authorize',
        ]]);
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('Set-Cookie'));
    }

    public function test_sign_in_methods_follow_the_login_page_and_reveal_no_secrets(): void
    {
        config([
            'services.google.client_id' => 'google-client-id-123',
            'services.google.client_secret' => 'google-secret-456',
            'services.github.client_id' => 'github-client-id-123',
            'services.github.client_secret' => 'github-secret-456',
            'services.microsoft.client_id' => 'ms-client-id-123',
            'services.microsoft.client_secret' => 'ms-secret-456',
            'services.apple.client_id' => 'com.example.apple-web',
            'services.apple.team_id' => 'TEAM123456',
            'services.apple.key_id' => 'KEY1234567',
            'services.apple.private_key' => '-----BEGIN PRIVATE KEY-----\\nnot-a-real-key\\n-----END PRIVATE KEY-----',
        ]);

        $response = $this->getJson('/api/v1/instance')
            ->assertOk()
            ->assertJsonPath('data.sign_in', ['password' => true, 'google' => true, 'github' => true, 'microsoft' => true, 'apple' => true]);

        $body = $response->getContent();
        foreach (['google-client-id-123', 'google-secret-456', 'github-secret-456', 'ms-client-id-123', 'ms-secret-456', 'com.example.apple-web', 'TEAM123456', 'KEY1234567', 'not-a-real-key', (string) config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        // the same rule as the login page
        $this->get('/login')->assertInertia(fn ($page) => $page
            ->where('canLoginWithGoogle', true)
            ->where('canLoginWithGitHub', true)
            ->where('canLoginWithMicrosoft', true)
            ->where('canLoginWithApple', true));

        // Microsoft needs the secret too
        config(['services.microsoft.client_secret' => null]);
        $this->getJson('/api/v1/instance')->assertJsonPath('data.sign_in.microsoft', false);

        // Apple needs the key too
        config(['services.apple.private_key' => null]);
        $this->getJson('/api/v1/instance')->assertJsonPath('data.sign_in.apple', false);
    }

    public function test_it_reflects_the_admin_settings(): void
    {
        InstanceSettings::instance()->update(['settings' => [
            'allow_registration' => false,
            'require_email_verification' => false,
        ]]);

        $this->getJson('/api/v1/instance')
            ->assertOk()
            ->assertJsonPath('data.sign_up_open', false)
            ->assertJsonPath('data.email_verification_required', false);
    }

    public function test_only_get_is_allowed(): void
    {
        $this->postJson('/api/v1/instance')
            ->assertStatus(405)
            ->assertJsonPath('code', 'method_not_allowed');
    }
}
