<?php

namespace Tests\Feature\Notifications;

use App\Jobs\SendMobilePush;
use App\Models\AppDevice;
use App\Models\User;
use App\Notifications\Channels\MobilePushChannel;
use App\Notifications\MentionNotification;
use App\Support\AppApi\AppTokens;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/** Push to the mobile app through the Expo push service (BRAI-448). */
class MobilePushTest extends TestCase
{
    use RefreshDatabase;

    private const EXPO = 'https://exp.host/--/api/v2/push/send';

    private const IPHONE = 'ExponentPushToken[iphone-secret-aaaa]';

    private const PIXEL = 'ExponentPushToken[pixel-secret-bbbb]';

    private User $user;

    private User $actor;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://brainitive.test', 'app-api.push.enabled' => true, 'app-api.push.expo_access_token' => null]);
        $this->user = User::factory()->create();
        $this->actor = User::factory()->create(['name' => 'Ada']);
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e);
    }

    private function device(array $attributes = []): AppDevice
    {
        return AppDevice::create([
            'user_id' => $this->user->id,
            'client' => 'mobile',
            'platform' => 'ios',
            'name' => 'Phone',
            'app_version' => '0.1.0',
            ...$attributes,
        ]);
    }

    private function mention(): MentionNotification
    {
        return new MentionNotification('item', 'Fix login', 'https://brainitive.test/workspaces/acme/projects/web/boards/main/items/412?tab=comments', $this->actor);
    }

    /** @param  list<array<string, mixed>>  $tickets */
    private function fakeExpo(array $tickets = [], int $status = 200): void
    {
        Http::fake([self::EXPO => Http::response($status === 200 ? ['data' => $tickets] : ['errors' => [['code' => 'INTERNAL_SERVER_ERROR', 'message' => 'oops']]], $status)]);
    }

    private function assertNoTokenLogged(): void
    {
        foreach ($this->logged as $entry) {
            $text = $entry->message.' '.json_encode($entry->context);
            $this->assertStringNotContainsString('secret', $text, 'a push token was logged');
            $this->assertStringNotContainsString('PushToken[', $text, 'a push token was logged');
        }
    }

    public function test_an_opted_in_category_is_pushed_to_the_users_mobile_devices_only(): void
    {
        config(['app-api.push.expo_access_token' => 'expo-access']);
        $iphone = $this->device(['push_token' => self::IPHONE]);
        $pixel = $this->device(['platform' => 'android', 'push_token' => self::PIXEL]);
        $this->device(['client' => 'desktop', 'platform' => 'macos', 'push_token' => 'ExponentPushToken[desktop]']);
        $this->device(['client' => 'tv', 'platform' => 'android_tv', 'push_token' => 'ExponentPushToken[tv]']);
        AppTokens::revoke($this->device(['push_token' => 'ExponentPushToken[revoked]']), 'user');
        $this->device(['push_token' => null]);
        AppDevice::create(['user_id' => User::factory()->create()->id, 'client' => 'mobile', 'platform' => 'ios', 'name' => 'Other', 'push_token' => 'ExponentPushToken[someone-else]']);
        $this->fakeExpo([['status' => 'ok', 'id' => 'a'], ['status' => 'ok', 'id' => 'b']]);

        $this->user->notify($this->mention()); // mentions: push on by default

        $notificationId = $this->user->notifications()->sole()->id;
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($notificationId) {
            $messages = $request->data();

            return $request->url() === self::EXPO
                && $request->hasHeader('Authorization', 'Bearer expo-access')
                && array_column($messages, 'to') === [self::IPHONE, self::PIXEL]
                && $messages[0] === [
                    'to' => self::IPHONE,
                    'title' => 'Ada mentioned you',
                    'body' => 'In item: Fix login',
                    'sound' => 'default',
                    'badge' => 1,
                    'priority' => 'high',
                    'data' => ['url' => '/workspaces/acme/projects/web/boards/main/items/412?tab=comments', 'notification_id' => $notificationId],
                ];
        });
        $this->assertSame(self::IPHONE, $iphone->fresh()->push_token);
        $this->assertSame(self::PIXEL, $pixel->fresh()->push_token);
    }

    public function test_an_opted_out_category_sends_nothing(): void
    {
        $this->device(['push_token' => self::IPHONE]);
        $this->user->update(['notification_preferences' => ['mentions' => ['push' => false]]]);
        $this->fakeExpo();

        $this->user->fresh()->notify($this->mention());

        $this->assertSame(1, $this->user->notifications()->count()); // the bell still gets it
        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_without_a_mobile_device_or_when_disabled(): void
    {
        $this->device(['client' => 'desktop', 'platform' => 'macos', 'push_token' => self::IPHONE]);
        $this->fakeExpo();
        $this->user->notify($this->mention());

        $this->device(['push_token' => self::PIXEL]);
        config(['app-api.push.enabled' => false]);
        $this->user->notify($this->mention());

        Http::assertNothingSent();
    }

    public function test_device_not_registered_clears_that_devices_token_and_logs_no_token(): void
    {
        $iphone = $this->device(['push_token' => self::IPHONE]);
        $pixel = $this->device(['platform' => 'android', 'push_token' => self::PIXEL]);
        $this->fakeExpo([
            ['status' => 'ok', 'id' => 'a'],
            ['status' => 'error', 'message' => '"'.self::PIXEL.'" is not a registered push notification recipient', 'details' => ['error' => 'DeviceNotRegistered', 'expoPushToken' => self::PIXEL]],
        ]);

        $this->user->notify($this->mention());

        $this->assertSame(self::IPHONE, $iphone->fresh()->push_token);
        $this->assertNull($pixel->fresh()->push_token);
        $this->assertNotEmpty($this->logged);
        $this->assertNoTokenLogged();
    }

    public function test_other_ticket_errors_and_a_failing_expo_are_logged_without_tokens_and_never_throw(): void
    {
        $iphone = $this->device(['push_token' => self::IPHONE]);
        $this->fakeExpo([['status' => 'error', 'message' => 'Message for '.self::IPHONE.' too big', 'details' => ['error' => 'MessageTooBig']]]);
        $this->user->notify($this->mention());

        $this->fakeExpo(status: 503);
        $this->user->notify($this->mention()); // sync queue: one attempt, no exception

        $this->assertSame(self::IPHONE, $iphone->fresh()->push_token);
        $this->assertCount(2, $this->logged);
        $this->assertSame('MessageTooBig', $this->logged[0]->context['error']);
        $this->assertStringContainsString('[push token]', $this->logged[0]->context['message']);
        $this->assertNoTokenLogged();
    }

    public function test_devices_are_batched_and_the_queued_job_carries_no_token(): void
    {
        config(['app-api.push.batch_size' => 2]);
        foreach (range(1, 3) as $n) {
            $this->device(['push_token' => "ExponentPushToken[secret-{$n}]"]);
        }
        Queue::fake();

        $this->user->notify($this->mention());

        Queue::assertPushed(SendMobilePush::class, 2);
        Queue::assertPushed(SendMobilePush::class, fn (SendMobilePush $job) => count($job->deviceIds) === 2);
        Queue::assertPushed(SendMobilePush::class, fn (SendMobilePush $job) => ! str_contains(serialize($job), 'PushToken'));
    }

    public function test_a_retryable_failure_is_released_with_backoff_on_a_real_queue(): void
    {
        $device = $this->device(['push_token' => self::IPHONE]);
        $this->fakeExpo(status: 500);
        $job = new SendMobilePush($this->user->id, [$device->id], 'T', 'B', '/x', null);
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(30);
        $queueJob->shouldReceive('isReleased', 'isDeleted', 'hasFailed')->andReturn(false);
        $job->setJob($queueJob);

        $job->handle();

        $this->assertSame([], $this->logged);
    }

    public function test_the_web_path_keeps_our_paths_and_drops_foreign_hosts(): void
    {
        $this->assertSame('/workspaces/acme?x=1#c', MobilePushChannel::webPath('https://brainitive.test/workspaces/acme?x=1#c'));
        $this->assertSame('/calendar', MobilePushChannel::webPath('/calendar'));
        $this->assertNull(MobilePushChannel::webPath('https://evil.example/workspaces'));
        $this->assertNull(MobilePushChannel::webPath('mailto:a@b.c'));
        $this->assertNull(MobilePushChannel::webPath(null));
        $this->assertNull(MobilePushChannel::webPath('//evil.example/x'));
        $this->assertNull(MobilePushChannel::webPath('/\\evil.example/x'));
        $this->assertNull(MobilePushChannel::webPath('/workspaces\\acme'));
    }
}
