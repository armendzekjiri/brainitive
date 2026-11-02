<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Codex web review Q: a malformed guest `return_to` (an array) falls back to "/" instead of a 500.
 */
class PortalReturnToTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_malformed_return_to_falls_back_to_the_portal_home(): void
    {
        $this->get('/guest/login?return_to[]=x')->assertOk();
        $this->post(route('guest.logout'), ['return_to' => ['x']])->assertRedirect('/');
        $this->post(route('guest.logout'), ['return_to' => 'https://evil.example/feedback/'])->assertRedirect('/');
        $this->post(route('guest.logout'), ['return_to' => '//evil.example/feedback/'])->assertRedirect('/');
        $this->post(route('guest.logout'), ['return_to' => '/feedback/acme'])->assertRedirect('/feedback/acme');
    }
}
