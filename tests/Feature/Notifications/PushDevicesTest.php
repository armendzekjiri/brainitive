<?php

namespace Tests\Feature\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Push\DeviceLabel;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Web push devices + test notification (BRAI-154): the device list, removing a device, the
 * synchronous test push and how real pushes record the push service's answer. The push service
 * is a Guzzle MockHandler — no network.
 */
class PushDevicesTest extends TestCase
{
    use RefreshDatabase;

    /** A real P-256 public key + auth secret (the web-push docs' example) so payloads encrypt. */
    private const KEYS = ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'];

    private const WINDOWS_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = VAPID::createVapidKeys();
        config(['webpush.vapid' => ['subject' => 'mailto:push@example.test', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey'], 'pem_file' => null]]);
    }

    /** Answer the next pushes with these responses (or exceptions) and record every request. */
    private function pushService(Response|\Throwable ...$answers): void
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $this->sent = [];
        $stack->push(Middleware::history($this->sent));
        config(['webpush.client_options' => ['handler' => $stack]]);
    }

    private function subscribe(User $user, string $endpoint, ?string $userAgent = self::WINDOWS_CHROME): PushSubscription
    {
        $subscription = $user->updatePushSubscription($endpoint, self::KEYS['p256dh'], self::KEYS['auth'], 'aes128gcm');
        $subscription->forceFill(['user_agent' => $userAgent, 'device_label' => DeviceLabel::label($userAgent)])->save();

        return $subscription->fresh();
    }

    public function test_subscribing_captures_the_browser(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withHeader('User-Agent', self::WINDOWS_CHROME)
            ->postJson(route('push-subscriptions.store'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/pc', 'keys' => self::KEYS])
            ->assertOk();

        $subscription = PushSubscription::firstWhere('endpoint', 'https://fcm.googleapis.com/fcm/send/pc');
        $this->assertSame(self::WINDOWS_CHROME, $subscription->user_agent);
        $this->assertSame('Chrome on Windows', $subscription->device_label);

        // a re-registration of a flagged subscription clears the flag; a huge user agent is cut
        $subscription->forceFill(['expired_at' => now()])->save();
        $this->actingAs($user)->withHeader('User-Agent', str_repeat('x', 600))
            ->postJson(route('push-subscriptions.store'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/pc', 'keys' => self::KEYS])
            ->assertOk();
        $subscription->refresh();
        $this->assertNull($subscription->expired_at);
        $this->assertSame(255, strlen($subscription->user_agent));
        $this->assertSame(1, $user->pushSubscriptions()->count());
    }

    public function test_the_device_list_shows_only_own_subscriptions_without_endpoints_or_keys(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/pc');
        $this->subscribe($user, 'https://web.push.apple.com/QGx-iphone', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1');
        $this->subscribe($other, 'https://updates.push.services.mozilla.com/wpush/v2/other');

        $response = $this->actingAs($user)->getJson(route('push-subscriptions.index'))->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.label', 'Safari on iPhone');
        $response->assertJsonPath('data.0.service', 'Safari / iPhone');
        $response->assertJsonPath('data.0.platform', 'ios');
        $response->assertJsonPath('data.1.label', 'Chrome on Windows');
        $response->assertJsonPath('data.1.service', 'Chrome/Edge (Google push)');
        $response->assertJsonPath('data.1.service_host', 'fcm.googleapis.com');
        $response->assertJsonPath('data.1.endpoint_hash', hash('sha256', 'https://fcm.googleapis.com/fcm/send/pc'));
        $this->assertStringNotContainsString('fcm/send/pc', $response->getContent());
        $this->assertStringNotContainsString(self::KEYS['p256dh'], $response->getContent());
        $this->assertStringNotContainsString('wpush', $response->getContent());

    }

    public function test_a_user_can_remove_only_their_own_devices(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/mine');
        $theirs = $this->subscribe($other, 'https://fcm.googleapis.com/fcm/send/theirs');

        $this->actingAs($user)->deleteJson(route('push-subscriptions.remove', $theirs->id))->assertNotFound();
        $this->assertModelExists($theirs);

        $this->actingAs($user)->deleteJson(route('push-subscriptions.remove', $mine->id))->assertOk();
        $this->assertModelMissing($mine);
    }

    public function test_a_test_push_that_is_accepted_is_delivered_and_recorded(): void
    {
        $user = User::factory()->create();
        $pc = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/pc');
        $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/laptop');
        $this->pushService(new Response(201));

        $this->actingAs($user)->postJson(route('push-subscriptions.test', $pc->id))
            ->assertOk()
            ->assertJsonPath('result.status', 'delivered')
            ->assertJsonPath('result.http_status', 201)
            ->assertJsonPath('device.id', $pc->id)
            ->assertJsonPath('device.last_error', null);

        // exactly one push, to exactly that subscription, signed with VAPID and encrypted
        $this->assertCount(1, $this->sent);
        $request = $this->sent[0]['request'];
        $this->assertSame('https://fcm.googleapis.com/fcm/send/pc', (string) $request->getUri());
        $this->assertStringStartsWith('vapid ', $request->getHeaderLine('Authorization'));
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));

        $pc->refresh();
        $this->assertNotNull($pc->last_success_at);
        $this->assertNotNull($pc->last_used_at);
        $this->assertNull($pc->expired_at);
    }

    public function test_a_test_push_to_an_expired_subscription_flags_it(): void
    {
        $user = User::factory()->create();
        $pc = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/pc');
        $this->pushService(new Response(410, [], 'push subscription has unsubscribed or expired.'));

        $this->actingAs($user)->postJson(route('push-subscriptions.test', $pc->id))
            ->assertOk()
            ->assertJsonPath('result.status', 'expired')
            ->assertJsonPath('result.http_status', 410)
            ->assertJsonPath('device.last_error', 'HTTP 410: The push service no longer knows this subscription.');

        $pc->refresh();
        $this->assertModelExists($pc);
        $this->assertNotNull($pc->expired_at);
        $this->assertNotNull($pc->last_error_at);
        $this->assertCount(0, $user->routeNotificationForWebPush(), 'expired subscriptions get no more pushes');
    }

    public function test_a_refused_test_push_returns_the_push_services_reason_without_urls(): void
    {
        $user = User::factory()->create();
        $pc = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/pc');
        $this->pushService(new Response(400, [], "the key in the authorization header does not correspond to the sender ID used to subscribe this user.\n See https://example.test/help?token=secret"));

        $response = $this->actingAs($user)->postJson(route('push-subscriptions.test', $pc->id))
            ->assertOk()
            ->assertJsonPath('result.status', 'rejected')
            ->assertJsonPath('result.http_status', 400);

        $reason = $response->json('result.reason');
        $this->assertStringContainsString('does not correspond to the sender ID', $reason);
        $this->assertStringNotContainsString('https://', $reason);
        $this->assertStringNotContainsString('secret', $reason);
        $this->assertStringStartsWith('HTTP 400: the key in the authorization header', $pc->fresh()->last_error);
        $this->assertNull($pc->fresh()->expired_at);

        // Apple / Mozilla answer JSON: the reason is taken from it
        $this->pushService(new Response(403, ['Content-Type' => 'application/json'], '{"reason":"BadJwtToken"}'));
        $this->actingAs($user)->postJson(route('push-subscriptions.test', $pc->id))
            ->assertJsonPath('result.status', 'rejected')
            ->assertJsonPath('result.reason', 'BadJwtToken');
    }

    public function test_a_test_push_that_cannot_reach_the_push_service_is_a_network_error(): void
    {
        $user = User::factory()->create();
        $pc = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/pc');
        $this->pushService(new ConnectException('cURL error 6: Could not resolve host: fcm.googleapis.com (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://fcm.googleapis.com/fcm/send/pc', new Psr7Request('POST', 'https://fcm.googleapis.com/fcm/send/pc')));

        $response = $this->actingAs($user)->postJson(route('push-subscriptions.test', $pc->id))
            ->assertOk()
            ->assertJsonPath('result.status', 'network');
        $this->assertStringNotContainsString('fcm/send/pc', $response->getContent());
    }

    public function test_a_test_push_without_vapid_keys_is_unconfigured_and_sends_nothing(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
        $user = User::factory()->create();
        $pc = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/pc');
        $this->pushService(new Response(201));

        $this->actingAs($user)->postJson(route('push-subscriptions.test', $pc->id))
            ->assertOk()
            ->assertJsonPath('result.status', 'unconfigured');
        $this->assertCount(0, $this->sent);
        $this->assertNull($pc->fresh()->last_used_at);
    }

    public function test_only_the_owner_can_test_a_device_and_the_endpoint_is_throttled(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $this->subscribe($other, 'https://fcm.googleapis.com/fcm/send/theirs');
        $mine = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/mine');

        $this->pushService(...array_fill(0, 12, new Response(201)));
        $this->actingAs($user)->postJson(route('push-subscriptions.test', $theirs->id))->assertNotFound();
        $this->assertCount(0, $this->sent);

        $this->assertContains('throttle:10,1,push-subscriptions.test', Route::getRoutes()->getByName('push-subscriptions.test')->gatherMiddleware());
        // the 404 above counted too: 9 more pass, the 11th request in the minute is refused
        for ($i = 0; $i < 9; $i++) {
            $this->actingAs($user)->postJson(route('push-subscriptions.test', $mine->id))->assertOk();
        }
        $this->actingAs($user)->postJson(route('push-subscriptions.test', $mine->id))->assertTooManyRequests();
    }

    public function test_real_notification_pushes_record_success_and_flag_expired_devices(): void
    {
        $user = User::factory()->create();
        $mac = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/mac');
        $pc = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/pc');

        // flush() sends in queue order: mac, then pc; then the second notification (mac only).
        // One mock for both: the channel manager keeps its WebPushChannel (and client) once built.
        $this->pushService(new Response(201), new Response(404, [], 'Not found'), new Response(201));
        $user->notify(new PushOnlyNotification);

        $this->assertCount(2, $this->sent);
        $mac->refresh();
        $pc->refresh();
        $this->assertNotNull($mac->last_success_at);
        $this->assertNull($mac->expired_at);
        $this->assertModelExists($pc);
        $this->assertNotNull($pc->expired_at, 'kept and flagged instead of deleted');
        $this->assertSame('HTTP 404: The push service no longer knows this subscription.', $pc->last_error);

        // the flagged device gets nothing next time
        $user->notify(new PushOnlyNotification);
        $this->assertCount(3, $this->sent);
        $this->assertSame('https://fcm.googleapis.com/fcm/send/mac', (string) $this->sent[2]['request']->getUri());
    }

    public function test_expired_subscriptions_are_pruned_after_thirty_days(): void
    {
        $user = User::factory()->create();
        $old = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/old');
        $recent = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/recent');
        $live = $this->subscribe($user, 'https://fcm.googleapis.com/fcm/send/live');
        $old->forceFill(['expired_at' => now()->subDays(31)])->save();
        $recent->forceFill(['expired_at' => now()->subDays(2)])->save();

        $this->artisan('model:prune', ['--model' => [PushSubscription::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelExists($live);
    }

    public function test_device_labels_and_push_services(): void
    {
        $this->assertSame('Edge on Windows', DeviceLabel::label('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0'));
        $this->assertSame('Chrome on macOS', DeviceLabel::label('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'));
        $this->assertSame('Safari on macOS', DeviceLabel::label('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15'));
        $this->assertSame('Firefox on Linux', DeviceLabel::label('Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0'));
        $this->assertSame('Chrome on Android', DeviceLabel::label('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'));
        $this->assertNull(DeviceLabel::label(null));

        $this->assertSame('Firefox', DeviceLabel::service('https://updates.push.services.mozilla.com/wpush/v2/x')['name']);
        $this->assertSame('Edge (Windows push)', DeviceLabel::service('https://wns2-par02p.notify.windows.com/w/?token=x')['name']);
        $this->assertSame('Safari / iPhone', DeviceLabel::service('https://web.push.apple.com/abc')['name']);
    }
}

/** A push-only notification going through the real WebPushChannel (same path as every catalog notification). */
class PushOnlyNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        return (new WebPushMessage)->title('Hello')->body('World')->data(['url' => '/']);
    }
}
