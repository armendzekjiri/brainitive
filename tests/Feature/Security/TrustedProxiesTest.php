<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Codex config review 3: proxy trust is explicit (TRUSTED_PROXIES, bootstrap/app.php). Without it,
 * X-Forwarded-* from a client are ignored: no faked client IP (login throttling), scheme or host.
 */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_forwarded_headers_from_an_untrusted_client_are_ignored(): void
    {
        $this->assertEmpty(env('TRUSTED_PROXIES'), 'the test suite runs without trusted proxies');
        $this->withoutVite();

        $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example.com',
        ])->get('/login')->assertOk();

        $this->assertSame('127.0.0.1', request()->ip());
        $this->assertFalse(request()->isSecure());
        $this->assertSame('localhost', request()->getHost());
    }
}
