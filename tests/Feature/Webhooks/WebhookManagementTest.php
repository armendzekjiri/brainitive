<?php

namespace Tests\Feature\Webhooks;

use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use App\Support\Webhooks\WebhookUrl;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Workspace settings → Webhooks (BRAI-104): admin-only CRUD, secret handling, SSRF guard,
 * Send test and Redeliver.
 */
class WebhookManagementTest extends TestCase
{
    use InteractsWithBoards;
    use InteractsWithWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function create(array $data = []): TestResponse
    {
        return $this->actingAs($this->admin())->postJson($this->webhookRoute('webhooks.store'), $data + [
            'name' => 'Zapier',
            'url' => self::HOOK_URL,
            'events' => ['item.created', 'ticket.created'],
            'active' => true,
        ]);
    }

    public function test_admin_sees_the_page_without_any_secret(): void
    {
        $this->assertFileExists(resource_path('js/Pages/Workspaces/Webhooks.tsx'));
        $hook = $this->hook(['item.created'], ['secret' => 'whsec_do_not_leak']);

        $response = $this->actingAs($this->admin())->get($this->webhookRoute('webhooks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Workspaces/Webhooks')
                ->where('webhooks.0.id', $hook->id)
                ->where('webhooks.0.events', ['item.created'])
                ->missing('webhooks.0.secret')
                ->has('eventGroups.Issues'));

        $this->assertStringNotContainsString('whsec_do_not_leak', $response->getContent());
    }

    public function test_admin_creates_a_webhook_and_gets_the_secret_once(): void
    {
        $secret = $this->create()->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('secret');

        $webhook = Webhook::firstOrFail();
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame($secret, $webhook->secret);
        $this->assertSame(['item.created', 'ticket.created'], $webhook->events);
        $this->assertSame($this->admin()->id, $webhook->created_by);
        // encrypted at rest, hidden from serialization
        $this->assertNotSame($secret, DB::table('webhooks')->value('secret'));
        $this->assertArrayNotHasKey('secret', $webhook->toArray());

        // "all events" wins over a list; unknown events are refused
        $this->create(['events' => ['item.created', '*']])->assertCreated();
        $this->assertSame(['*'], Webhook::latest('id')->first()->events);
        $this->create(['events' => ['item.exploded']])->assertUnprocessable()->assertJsonValidationErrors('events.0');
        $this->create(['events' => []])->assertUnprocessable()->assertJsonValidationErrors('events');
    }

    public function test_members_and_outsiders_get_403_and_other_workspaces_404(): void
    {
        $hook = $this->hook();
        foreach ([$this->editor, $this->outsider] as $user) {
            $this->actingAs($user)->get($this->webhookRoute('webhooks.index'))->assertForbidden();
            $this->actingAs($user)->postJson($this->webhookRoute('webhooks.store'), ['name' => 'x', 'url' => self::HOOK_URL, 'events' => ['*']])->assertForbidden();
            $this->actingAs($user)->postJson($this->webhookRoute('webhooks.secret', [$hook->id]))->assertForbidden();
            $this->actingAs($user)->postJson($this->webhookRoute('webhooks.test', [$hook->id]))->assertForbidden();
            $this->actingAs($user)->getJson($this->webhookRoute('webhooks.deliveries', [$hook->id]))->assertForbidden();
            $this->actingAs($user)->deleteJson($this->webhookRoute('webhooks.destroy', [$hook->id]))->assertForbidden();
        }

        // an admin of another workspace can't reach this workspace's webhook through their own
        $other = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $other->id]);
        $otherWorkspace->addUser($other, 'admin');
        $this->actingAs($other)->postJson(route('webhooks.secret', [$otherWorkspace->slug, $hook->id]))->assertNotFound();
        $this->actingAs($other)->deleteJson(route('webhooks.destroy', [$otherWorkspace->slug, $hook->id]))->assertNotFound();
        $this->assertNotNull($hook->fresh());
    }

    public function test_private_and_internal_urls_are_refused(): void
    {
        foreach (['http://127.0.0.1/hook', 'http://localhost/hook', 'http://10.0.0.5/hook', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/hook', 'ftp://93.184.216.34/x', 'https://user:pw@93.184.216.34/x'] as $url) {
            $this->create(['url' => $url])->assertUnprocessable()->assertJsonValidationErrors('url');
        }
        $this->assertSame(0, Webhook::count());
    }

    public function test_the_private_target_switch_only_works_in_the_e2e_environment(): void
    {
        config(['webhooks.allow_private_targets' => true]);

        // testing (and production, staging, local): ignored
        $this->assertFalse(WebhookUrl::allowPrivate());
        $this->create(['url' => 'http://127.0.0.1:9/hook'])->assertUnprocessable();
        $this->app['env'] = 'production';
        $this->assertFalse(WebhookUrl::allowPrivate());

        $this->withoutMiddleware(ValidateCsrfToken::class); // only skipped by itself in "testing"
        $this->app['env'] = 'e2e';
        $this->assertTrue(WebhookUrl::allowPrivate());
        $this->create(['url' => 'http://127.0.0.1:9/hook'])->assertCreated();

        config(['webhooks.allow_private_targets' => false]);
        $this->assertFalse(WebhookUrl::allowPrivate());
    }

    public function test_https_is_required_in_production(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->app['env'] = 'production';
        $this->create(['url' => 'http://93.184.216.34/hook'])->assertUnprocessable()->assertJsonValidationErrors(['url' => 'https']);
        $this->create(['url' => 'https://93.184.216.34/hook'])->assertCreated();
    }

    public function test_reveal_and_rotate_the_secret(): void
    {
        $hook = $this->hook();

        $this->actingAs($this->admin())->postJson($this->webhookRoute('webhooks.secret', [$hook->id]))
            ->assertOk()->assertJsonPath('secret', 'whsec_test');

        $new = $this->actingAs($this->admin())->postJson($this->webhookRoute('webhooks.rotate', [$hook->id]))->assertOk()->json('secret');
        $this->assertNotSame('whsec_test', $new);
        $this->assertSame($new, $hook->fresh()->secret);
    }

    public function test_update_and_re_enable_reset_the_failure_count(): void
    {
        $hook = $this->hook(['*'], ['active' => false, 'failure_count' => 10, 'disabled_at' => now()]);

        $this->actingAs($this->admin())->putJson($this->webhookRoute('webhooks.update', [$hook->id]), [
            'name' => 'Renamed', 'url' => 'https://93.184.216.35/new', 'events' => ['lead.created'], 'active' => true,
        ])->assertOk()->assertJsonPath('webhook.active', true);

        $hook->refresh();
        $this->assertSame(['Renamed', 'https://93.184.216.35/new', ['lead.created'], 0, null], [$hook->name, $hook->url, $hook->events, $hook->failure_count, $hook->disabled_at]);
        $this->assertSame('whsec_test', $hook->secret); // editing never changes the secret

        $this->actingAs($this->admin())->deleteJson($this->webhookRoute('webhooks.destroy', [$hook->id]))->assertOk();
        $this->assertNull($hook->fresh());
    }

    public function test_send_test_pings_and_redeliver_resends_the_same_event(): void
    {
        Http::fake(['*' => Http::response('pong', 200)]);
        $hook = $this->hook(['item.created']);

        $delivery = $this->actingAs($this->admin())->postJson($this->webhookRoute('webhooks.test', [$hook->id]))
            ->assertOk()
            ->assertJsonPath('delivery.event', 'ping')
            ->assertJsonPath('delivery.status', 'success') // inline on the sync queue
            ->assertJsonPath('delivery.response_code', 200)
            ->json('delivery');
        $this->assertSame('ping', $delivery['payload']['event']);
        $this->assertSame($this->admin()->name, $delivery['payload']['actor']['name']);

        $copy = $this->actingAs($this->admin())->postJson($this->webhookRoute('webhooks.redeliver', [$hook->id, $delivery['id']]))
            ->assertOk()->json('delivery');
        $this->assertNotSame($delivery['uuid'], $copy['uuid']);
        $this->assertSame($delivery['event_id'], $copy['event_id']); // receivers can dedupe by envelope id
        Http::assertSentCount(2);

        $this->actingAs($this->admin())->getJson($this->webhookRoute('webhooks.deliveries', [$hook->id]))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $copy['id'])->assertJsonPath('has_more', false);

        // a delivery of another webhook can't be redelivered through this one
        $other = $this->hook();
        $this->actingAs($this->admin())->postJson($this->webhookRoute('webhooks.redeliver', [$other->id, $delivery['id']]))->assertNotFound();
    }

    public function test_test_and_redeliver_are_throttled(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $hook = $this->hook();
        foreach (range(1, 10) as $i) {
            $this->actingAs($this->admin())->postJson($this->webhookRoute('webhooks.test', [$hook->id]))->assertOk();
        }
        $this->actingAs($this->admin())->postJson($this->webhookRoute('webhooks.test', [$hook->id]))->assertTooManyRequests();
        $this->assertSame(10, WebhookDelivery::count());
        Http::assertSent(fn (HttpRequest $request) => $request->header('X-Brainitive-Event')[0] === 'ping');
    }
}
