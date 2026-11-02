<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\Feature\Concerns\UsesPassportKeys;
use Tests\TestCase;

/**
 * Codex review 13, step 1 (v1.49.0): MCP takes tokens with `mcp:use` (or `*`) — OAuth connectors and the CLI's new
 * scoped tokens — plus, for now, the CLI's legacy scope-less tokens (logged once per token and day). Browser
 * extension tokens and every other scope-less token are refused.
 */
class McpScopeTest extends TestCase
{
    use RefreshDatabase;
    use UsesPassportKeys;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePassportKeys();
        app(ClientRepository::class)->createPersonalAccessGrantClient('Personal access', 'users');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'password' => bcrypt('secret-pass')]);
    }

    /** A token as issued before v1.49.0: no scopes at all (createToken() now adds the default `mcp:use`). */
    private function scopeLessToken(string $name): string
    {
        Passport::defaultScopes('');
        try {
            return $this->user->createToken($name)->accessToken;
        } finally {
            Passport::defaultScopes(['mcp:use']);
        }
    }

    private function mcp(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$token]);
    }

    public function test_tokens_with_the_mcp_scope_work(): void
    {
        $this->mcp($this->user->createToken('connector', ['mcp:use'])->accessToken)->assertOk();
        $this->mcp($this->user->createToken('admin', ['*'])->accessToken)->assertOk();
    }

    public function test_a_new_cli_token_is_scoped_and_works(): void
    {
        $token = $this->postJson('/api/cli/login', ['email' => $this->user->email, 'password' => 'secret-pass'])->assertOk()->json('token');

        $this->assertEqualsCanonicalizing(['cli', 'mcp:use'], $this->user->tokens()->where('name', 'brainitive-cli')->latest()->first()->scopes);
        $this->mcp($token)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/cli/me', ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_a_legacy_cli_token_still_works_and_is_logged_once_a_day(): void
    {
        Log::spy();
        $token = $this->scopeLessToken('brainitive-cli');

        $this->mcp($token)->assertOk();
        $this->mcp($token)->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'legacy scope-less CLI token'))->once();
    }

    public function test_capture_and_other_scope_less_tokens_are_refused(): void
    {
        $this->mcp($this->user->createToken('extension', ['capture'])->accessToken)->assertForbidden();
        $this->mcp($this->scopeLessToken('something-else'))->assertForbidden();
    }
}
