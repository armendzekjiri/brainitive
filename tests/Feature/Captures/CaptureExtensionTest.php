<?php

namespace Tests\Feature\Captures;

use App\Models\Capture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\Feature\Concerns\UsesPassportKeys;
use Tests\TestCase;

/**
 * Quick capture (BRAI-70) from the browser extension: "Save to inbox" needs the capture scope,
 * and a capture token can only create — never read the inbox.
 */
class CaptureExtensionTest extends TestCase
{
    use RefreshDatabase;
    use UsesPassportKeys;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->usePassportKeys();
        $this->user = User::factory()->create();
    }

    public function test_save_to_inbox_with_a_capture_token(): void
    {
        Passport::actingAs($this->user, ['capture']);

        $this->postJson(route('ext.captures.store'), [
            'text' => "Compare with Linear's triage\nmaybe for helpdesk",
            'url' => 'https://linear.app/docs/triage',
        ])->assertCreated()
            ->assertJsonPath('location.type', 'capture')
            ->assertJsonPath('location.url', route('captures.index'));

        $capture = Capture::sole();
        $this->assertSame($this->user->id, $capture->user_id);
        $this->assertSame('Browser extension', $capture->source);
        $this->assertSame('inbox', $capture->state());

        $this->postJson(route('ext.captures.store'), ['text' => 'x', 'url' => 'javascript:alert(1)'])->assertJsonValidationErrors('url');
        $this->postJson(route('ext.captures.store'), ['text' => 'x', 'source' => 'Article title'])->assertCreated();
        $this->assertSame('Article title', Capture::latest('id')->first()->source);
    }

    public function test_tokens_without_the_capture_scope_are_refused(): void
    {
        Passport::actingAs($this->user, ['mcp:use']);
        $this->postJson(route('ext.captures.store'), ['text' => 'x'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson(route('ext.captures.store'), ['text' => 'x'])->assertUnauthorized();

        $this->assertSame(0, Capture::count());
    }

    public function test_a_real_capture_token_cannot_read_or_change_the_inbox(): void
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test personal access');
        $token = $this->user->createToken('extension', ['capture'])->accessToken;
        $capture = Capture::factory()->create(['user_id' => $this->user->id, 'text' => 'Private']);

        $bearer = ['Authorization' => 'Bearer '.$token];

        // the inbox is a session-only web surface…
        $this->withHeaders($bearer)->getJson(route('captures.index'))->assertUnauthorized();
        $this->withHeaders($bearer)->getJson(route('captures.destinations'))->assertUnauthorized();
        $this->withHeaders($bearer)->postJson(route('captures.convert', $capture), ['type' => 'issue', 'destination_id' => 1, 'fields' => []])->assertUnauthorized();
        $this->withHeaders($bearer)->patchJson(route('captures.update', $capture), ['text' => 'Changed'])->assertUnauthorized();
        $this->assertSame('Private', $capture->fresh()->text);

        // …the token only works where it belongs (the api guard request goes last: it switches
        // the test app's default guard)
        $this->withHeaders($bearer)->getJson('/api/cli/me')->assertForbidden();
        $this->withHeaders($bearer)->postJson(route('ext.captures.store'), ['text' => 'From the extension'])->assertCreated();
        $this->assertSame(2, Capture::count());
    }
}
