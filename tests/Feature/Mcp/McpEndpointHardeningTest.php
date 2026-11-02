<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Passport;
use Tests\Feature\Concerns\UsesPassportKeys;
use Tests\TestCase;

/**
 * Codex review, findings 9 / 12: `/mcp` wrote caller-controlled JSON (the JSON-RPC `method`) to the log before
 * authentication (a temporary diagnostic from May 2026), and had no throttle.
 */
class McpEndpointHardeningTest extends TestCase
{
    use RefreshDatabase;
    use UsesPassportKeys;

    public function test_unauthenticated_requests_write_nothing_of_the_body_to_the_log(): void
    {
        $this->usePassportKeys();
        $long = str_repeat('A', 20000);
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $long, 'params' => ['protocolVersion' => $long]])->assertUnauthorized();
        $this->get('/mcp', ['Accept' => 'text/event-stream'])->assertStatus(405);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString(str_repeat('A', 100), $line, 'the caller-controlled method is never logged');
            $this->assertStringNotContainsString('[MCP-DEBUG]', $line);
        }
    }

    public function test_the_endpoint_is_throttled_per_user(): void
    {
        $this->usePassportKeys();
        $user = User::factory()->create();
        Passport::actingAs($user, ['mcp:use']);
        $ping = fn () => $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        for ($i = 0; $i < 120; $i++) {
            $ping()->assertOk();
        }
        $ping()->assertStatus(429);

        // another user has their own budget
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $ping()->assertOk();
    }
}
