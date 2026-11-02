<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\GuardMcpEndpoint;
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

    /**
     * Codex review, finding 13 (enforced since v1.49.0, McpScopeTest has the details): a scope-less token from
     * Passport::actingAs isn't the CLI's legacy token, so it no longer reaches MCP; `mcp:use` does, `capture` doesn't.
     */
    public function test_mcp_needs_the_mcp_scope_and_capture_tokens_stay_out(): void
    {
        $this->usePassportKeys();
        $user = User::factory()->create();
        $list = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

        Passport::actingAs($user);
        $this->postJson('/mcp', $list)->assertForbidden();

        Passport::actingAs($user, ['mcp:use']);
        $this->postJson('/mcp', $list)->assertOk();

        Passport::actingAs($user, ['capture']);
        $this->postJson('/mcp', $list)->assertForbidden();
    }

    /** Codex recheck, item AA: unauthenticated attempts are limited per IP before authentication; signed-in clients aren't. */
    public function test_unauthenticated_attempts_are_throttled_per_ip_and_bodies_are_capped(): void
    {
        $this->usePassportKeys();
        $list = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

        for ($i = 0; $i < GuardMcpEndpoint::UNAUTHENTICATED_PER_MINUTE; $i++) {
            $this->postJson('/mcp', $list)->assertUnauthorized();
        }
        $this->postJson('/mcp', $list)->assertStatus(429);

        // a signed-in client from another address is not affected, and its own calls never count
        $user = User::factory()->create();
        Passport::actingAs($user, ['mcp:use']);
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->postJson('/mcp', $list)->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->call('POST', '/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], str_repeat('x', GuardMcpEndpoint::MAX_BYTES + 1))
            ->assertStatus(413);
    }
}
