<?php

namespace Tests\Feature\Notifications;

use App\Jobs\CheckMobilePushReceipts;
use App\Jobs\SendMobilePush;
use App\Models\AppDevice;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/** Expo push receipts: checked ~15 min after sending; DeviceNotRegistered clears the token (BRAI-448). */
class MobilePushReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private const SEND = 'https://exp.host/--/api/v2/push/send';

    private const RECEIPTS = 'https://exp.host/--/api/v2/push/getReceipts';

    private const IPHONE = 'ExponentPushToken[iphone-secret-aaaa]';

    private const PIXEL = 'ExponentPushToken[pixel-secret-bbbb]';

    private User $user;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app-api.push.enabled' => true, 'app-api.push.expo_access_token' => 'expo-access']);
        $this->user = User::factory()->create();
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e);
    }

    private function device(string $token): AppDevice
    {
        $device = AppDevice::create([
            'user_id' => $this->user->id,
            'client' => 'mobile',
            'platform' => 'ios',
            'name' => 'Phone',
            'app_version' => '0.1.0',
            'push_token' => $token,
        ]);
        // signed in: a refresh token that hasn't expired
        $device->forceFill(['refresh_expires_at' => now()->addDays(30)])->save();

        return $device;
    }

    /** A SendMobilePush that runs as on a real queue (attempt $attempt). */
    private function queued(object $job, int $attempt = 1): Mockery\MockInterface
    {
        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->setJob($queueJob);

        return $queueJob;
    }

    /** @param  array<string, array{device_id: int, token_hash: string}>  $tickets */
    private function check(array $tickets): CheckMobilePushReceipts
    {
        return new CheckMobilePushReceipts($tickets);
    }

    private function assertNoTokenLogged(): void
    {
        foreach ($this->logged as $entry) {
            $text = $entry->message.' '.json_encode($entry->context);
            $this->assertStringNotContainsString('secret', $text, 'a push token was logged');
            $this->assertStringNotContainsString('PushToken[', $text, 'a push token was logged');
        }
    }

    public function test_ok_tickets_schedule_a_receipt_check_15_minutes_later_without_tokens(): void
    {
        Queue::fake();
        $iphone = $this->device(self::IPHONE);
        $pixel = $this->device(self::PIXEL);
        Http::fake([self::SEND => Http::response(['data' => [
            ['status' => 'ok', 'id' => 'ticket-iphone'],
            ['status' => 'error', 'message' => 'x', 'details' => ['error' => 'MessageTooBig']],
        ]])]);

        $job = new SendMobilePush($this->user->id, [$iphone->id, $pixel->id], 'Hi', 'There', null, null);
        $this->queued($job);
        $job->handle();

        Queue::assertPushed(CheckMobilePushReceipts::class, 1);
        Queue::assertPushed(CheckMobilePushReceipts::class, function (CheckMobilePushReceipts $check) use ($iphone) {
            return $check->tickets === ['ticket-iphone' => ['device_id' => $iphone->id, 'token_hash' => hash('sha256', self::IPHONE)]]
                && $check->delay instanceof CarbonInterface
                && abs($check->delay->diffInSeconds(now()->addSeconds(900))) < 5
                && ! str_contains(serialize($check), 'secret');
        });
    }

    public function test_no_receipt_check_on_a_sync_queue_or_without_ok_tickets(): void
    {
        Queue::fake();
        $iphone = $this->device(self::IPHONE);
        Http::fake([self::SEND => Http::sequence()
            ->push(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])
            ->push(['data' => [['status' => 'error', 'details' => ['error' => 'MessageRateExceeded']]]]),
        ]);

        (new SendMobilePush($this->user->id, [$iphone->id], 'Hi', 'There', null, null))->handle(); // no queue job: sync
        $job = new SendMobilePush($this->user->id, [$iphone->id], 'Hi', 'There', null, null);
        $this->queued($job);
        $job->handle();

        Queue::assertNotPushed(CheckMobilePushReceipts::class);
    }

    public function test_device_not_registered_receipt_clears_the_token_only_while_it_was_the_one_sent(): void
    {
        $iphone = $this->device(self::IPHONE);
        $pixel = $this->device(self::PIXEL);
        Http::fake([self::RECEIPTS => Http::response(['data' => [
            'ticket-iphone' => ['status' => 'error', 'message' => '"'.self::IPHONE.'" is not a registered push notification recipient', 'details' => ['error' => 'DeviceNotRegistered']],
            'ticket-pixel' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
            'ticket-ok' => ['status' => 'ok'],
        ]])]);
        // the Pixel registered a new token since that push
        $pixel->forceFill(['push_token' => 'ExponentPushToken[pixel-new]'])->save();

        $this->check([
            'ticket-iphone' => ['device_id' => $iphone->id, 'token_hash' => SendMobilePush::tokenHash(self::IPHONE)],
            'ticket-pixel' => ['device_id' => $pixel->id, 'token_hash' => SendMobilePush::tokenHash(self::PIXEL)],
            'ticket-ok' => ['device_id' => $pixel->id, 'token_hash' => SendMobilePush::tokenHash(self::PIXEL)],
            'ticket-not-ready' => ['device_id' => $pixel->id, 'token_hash' => SendMobilePush::tokenHash(self::PIXEL)],
        ])->handle();

        $this->assertNull($iphone->fresh()->push_token);
        $this->assertSame('ExponentPushToken[pixel-new]', $pixel->fresh()->push_token);
        Http::assertSent(fn (Request $request) => $request->url() === self::RECEIPTS
            && $request->method() === 'POST'
            && $request['ids'] === ['ticket-iphone', 'ticket-pixel', 'ticket-ok', 'ticket-not-ready']
            && $request->hasHeader('Authorization', 'Bearer expo-access'));
        $this->assertNoTokenLogged();
    }

    public function test_other_receipt_errors_are_logged_without_tokens(): void
    {
        $iphone = $this->device(self::IPHONE);
        Http::fake([self::RECEIPTS => Http::response(['data' => [
            'ticket-1' => ['status' => 'error', 'message' => 'The Apple credentials for '.self::IPHONE.' are invalid', 'details' => ['error' => 'InvalidCredentials']],
        ]])]);

        $this->check(['ticket-1' => ['device_id' => $iphone->id, 'token_hash' => SendMobilePush::tokenHash(self::IPHONE)]])->handle();

        $this->assertSame(self::IPHONE, $iphone->fresh()->push_token);
        $warning = collect($this->logged)->firstWhere('message', 'Mobile push: delivery failed (receipt)');
        $this->assertNotNull($warning);
        $this->assertSame('InvalidCredentials', $warning->context['error']);
        $this->assertSame($iphone->id, $warning->context['device_id']);
        $this->assertNoTokenLogged();
    }

    public function test_a_failing_receipts_request_is_retried_then_given_up_without_throwing(): void
    {
        $iphone = $this->device(self::IPHONE);
        Http::fake([self::RECEIPTS => Http::response(['errors' => [['code' => 'INTERNAL']]], 503)]);
        $tickets = ['ticket-1' => ['device_id' => $iphone->id, 'token_hash' => SendMobilePush::tokenHash(self::IPHONE)]];

        $job = $this->check($tickets);
        $this->queued($job, 1)->shouldReceive('release')->once()->with(60);
        $job->handle();

        $last = $this->check($tickets);
        $this->queued($last, 4)->shouldNotReceive('release');
        $last->handle();

        $this->assertNotNull(collect($this->logged)->firstWhere('message', 'Mobile push receipts: not fetched'));
        $this->assertSame(self::IPHONE, $iphone->fresh()->push_token);
        $this->assertNoTokenLogged();
    }
}
