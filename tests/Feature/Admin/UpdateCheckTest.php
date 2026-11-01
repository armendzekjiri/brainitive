<?php

namespace Tests\Feature\Admin;

use App\Models\InstanceSettings;
use App\Support\AppVersion;
use App\Support\Imports\Archive\ProjectArchive;
use App\Support\Updates\UpdateCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * BRAI-433: a daily check for a newer Brainitive release, shown to instance admins only. Never
 * calls GitHub for real (Http::fake), never on a page request.
 */
class UpdateCheckTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
        config(['app.update_check' => true, 'app.version' => '1.47.3']);
    }

    protected function tearDown(): void
    {
        AppVersion::flush();
        parent::tearDown();
    }

    /** @var array<string, mixed>|null what the fake GitHub answers (Http::fake stubs stack, so one closure reads this) */
    private ?array $release = null;

    /** @param  array<string, mixed>  $overrides */
    private function fakeRelease(string $tag, array $overrides = []): void
    {
        $faked = $this->release !== null;
        $this->release = array_merge([
            'tag_name' => $tag,
            'html_url' => "https://github.com/armendzekjiri/brainitive/releases/tag/{$tag}",
            'published_at' => '2026-11-02T10:00:00Z',
            'draft' => false,
            'prerelease' => false,
        ], $overrides);

        if (! $faked) {
            Http::fake([UpdateCheck::ENDPOINT => fn () => Http::response($this->release)]);
        }
    }

    public function test_a_newer_release_is_stored_and_shown_to_instance_admins(): void
    {
        $this->fakeRelease('v1.48.0');

        $this->assertSame(['status' => 'ok'], UpdateCheck::run());

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.github.com/repos/armendzekjiri/brainitive/releases/latest'
            && $r->method() === 'GET'
            && $r->hasHeader('User-Agent', 'Brainitive')
            && ! $r->hasHeader('Authorization')
            && $r->body() === '');

        $stored = InstanceSettings::instance()->settings['update_check'];
        $this->assertSame('1.48.0', $stored['latest_version']);
        $this->assertSame('https://github.com/armendzekjiri/brainitive/releases/tag/v1.48.0', $stored['release_url']);
        $this->assertStringStartsWith('2026-11-02T10:00:00', $stored['published_at']);
        $this->assertNotNull($stored['checked_at']);

        $this->actingAs($this->admin)->get(route('admin.overview'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Overview')
                ->where('updates.enabled', true)
                ->where('updates.current', '1.47.3')
                ->where('updates.latest', '1.48.0')
                ->where('updates.update_available', true)
                ->where('updates.release_url', 'https://github.com/armendzekjiri/brainitive/releases/tag/v1.48.0')
                ->where('updates.upgrade_docs_url', UpdateCheck::UPGRADE_DOCS_URL)
                ->where('updateNotice.latest', '1.48.0')
                ->where('updateNotice.current', '1.47.3'));
    }

    public function test_the_same_or_an_older_release_is_no_update(): void
    {
        foreach (['v1.47.3', 'v1.47.2', 'v1.9.9'] as $tag) {
            $this->fakeRelease($tag);
            UpdateCheck::run();

            $status = UpdateCheck::status();
            $this->assertSame(ltrim($tag, 'v'), $status['latest'], $tag);
            $this->assertFalse($status['update_available'], $tag);
            $this->assertNull(UpdateCheck::noticeFor($this->admin), $tag);
        }

        // semantic, not string order: 1.100.0 > 1.47.3 > 1.9.9
        $this->assertTrue(AppVersion::isNewer('1.100.0', '1.47.3'));
        $this->assertFalse(AppVersion::isNewer('1.9.9', '1.47.3'));
        $this->assertTrue(AppVersion::isNewer('v2.0.0', '1.99.99'));
    }

    public function test_pre_releases_and_drafts_are_ignored_and_keep_the_last_value(): void
    {
        UpdateCheck::record('1.48.0');

        foreach ([
            ['v1.49.0', ['prerelease' => true]],
            ['v1.49.0', ['draft' => true]],
            ['v1.49.0-rc.1', []],
            ['nightly', []],
        ] as [$tag, $overrides]) {
            $this->fakeRelease($tag, $overrides);
            $this->assertSame('failed', UpdateCheck::run()['status'], $tag);
            $this->assertSame('1.48.0', UpdateCheck::status()['latest'], $tag);
        }
    }

    public function test_github_down_or_rate_limited_fails_silently_and_keeps_the_last_value(): void
    {
        UpdateCheck::record('1.48.0', 'https://github.com/armendzekjiri/brainitive/releases/tag/v1.48.0', '2026-11-02T10:00:00+00:00');
        $checkedAt = UpdateCheck::status()['checked_at'];

        $this->travel(1)->days();
        Http::fake([UpdateCheck::ENDPOINT => Http::response(['message' => 'API rate limit exceeded'], 403)]);
        $this->assertSame('failed', UpdateCheck::run()['status']);

        Http::fake([UpdateCheck::ENDPOINT => fn () => throw new ConnectionException('Could not resolve host')]);
        $this->assertSame('failed', UpdateCheck::run()['status']);

        Http::fake([UpdateCheck::ENDPOINT => Http::response('<html>bad gateway</html>', 502)]);
        $this->assertSame('failed', UpdateCheck::run()['status']);

        $status = UpdateCheck::status();
        $this->assertSame('1.48.0', $status['latest']);
        $this->assertSame($checkedAt, $status['checked_at']);
        $this->assertNotNull($status['failed_at']);
        $this->assertTrue($status['update_available']);

        // the command reports it without throwing
        $this->artisan('brainitive:check-update')->expectsOutputToContain('Could not check for updates')->assertFailed();
    }

    public function test_disabled_by_env_sends_nothing_and_shows_nothing(): void
    {
        UpdateCheck::record('1.48.0');
        config(['app.update_check' => false]);
        Http::fake();

        $this->assertSame(['status' => 'disabled'], UpdateCheck::run());
        $this->artisan('brainitive:check-update')->expectsOutputToContain('The update check is off')->assertSuccessful();
        Http::assertNothingSent();

        $this->assertNull(UpdateCheck::noticeFor($this->admin));
        $this->actingAs($this->admin)->get(route('admin.overview'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('updates.enabled', false)
                ->where('updates.update_available', false)
                ->where('updateNotice', null));
    }

    public function test_it_is_off_by_default_on_brainitive_cloud_and_dev_and_on_for_self_hosted(): void
    {
        config(['app.update_check' => null, 'legal.cloud' => null]);

        config(['app.url' => 'https://www.brainitive.app']);
        $this->assertFalse(UpdateCheck::enabled());
        config(['app.url' => 'https://dev.brainitive.app']);
        $this->assertFalse(UpdateCheck::enabled());

        config(['app.url' => 'https://brainitive.example.com']);
        $this->assertTrue(UpdateCheck::enabled());

        // LEGAL_CLOUD=true marks Cloud too; BRAINITIVE_UPDATE_CHECK always wins
        config(['legal.cloud' => true]);
        $this->assertFalse(UpdateCheck::enabled());
        config(['app.update_check' => 'true']);
        $this->assertTrue(UpdateCheck::enabled());
        config(['legal.cloud' => null, 'app.update_check' => 'false']);
        $this->assertFalse(UpdateCheck::enabled());
    }

    public function test_the_check_is_scheduled_daily(): void
    {
        Artisan::call('schedule:list');

        $this->assertMatchesRegularExpression('/5\s+5\s+\*\s+\*\s+\*\s+php artisan brainitive:check-update/', Artisan::output());
    }

    public function test_the_command_prints_the_result(): void
    {
        $this->fakeRelease('v1.48.0');

        $this->artisan('brainitive:check-update')
            ->expectsOutputToContain('Running Brainitive v1.47.3.')
            ->expectsOutputToContain('Latest release: v1.48.0 (published 2026-11-02).')
            ->expectsOutputToContain('Brainitive v1.48.0 is available.')
            ->expectsOutputToContain(UpdateCheck::UPGRADE_DOCS_URL)
            ->assertSuccessful();

        $this->fakeRelease('v1.47.3');
        $this->artisan('brainitive:check-update')->expectsOutputToContain('You are up to date.')->assertSuccessful();

        $this->artisan('brainitive:check-update --clear')->assertSuccessful();
        $this->assertNull(UpdateCheck::status()['latest']);

        // the e2e shortcut is refused everywhere else
        $this->artisan('brainitive:check-update --fake-latest=9.9.9')->assertFailed();
        $this->assertNull(UpdateCheck::status()['latest']);
    }

    public function test_pages_never_call_github(): void
    {
        Http::fake();

        $this->actingAs($this->admin)->get(route('admin.overview'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('updates.latest', null)->where('updates.checked_at', null));
        $this->actingAs($this->owner)->get(route('workspaces.index'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_regular_users_get_no_update_data(): void
    {
        UpdateCheck::record('1.48.0');

        $this->assertNull(UpdateCheck::noticeFor($this->owner));
        $this->actingAs($this->owner)->get(route('workspaces.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('updateNotice', null));

        $this->actingAs($this->owner)->get(route('admin.overview'))->assertForbidden();
        $this->actingAs($this->owner)->postJson(route('admin.update-notice.dismiss'), ['version' => '1.48.0'])->assertForbidden();
    }

    public function test_dismissing_hides_the_notice_until_a_newer_release(): void
    {
        UpdateCheck::record('1.48.0');
        $this->assertSame('1.48.0', UpdateCheck::noticeFor($this->admin)['latest']);

        $this->actingAs($this->admin)->postJson(route('admin.update-notice.dismiss'), ['version' => 'not-a-version'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson(route('admin.update-notice.dismiss'), ['version' => '1.48.0'])->assertOk();

        $this->assertSame('1.48.0', $this->admin->fresh()->onboarding_state[UpdateCheck::DISMISSED_KEY]);
        $this->assertNull(UpdateCheck::noticeFor($this->admin->fresh()));
        // the admin console keeps showing it
        $this->actingAs($this->admin->fresh())->get(route('admin.overview'))
            ->assertInertia(fn (Assert $page) => $page->where('updates.update_available', true)->where('updateNotice', null));

        UpdateCheck::record('1.48.1');
        $this->assertSame('1.48.1', UpdateCheck::noticeFor($this->admin->fresh())['latest']);
    }

    public function test_the_running_version_comes_from_the_newest_changelog_entry(): void
    {
        config(['app.version' => null]);
        AppVersion::flush();

        $source = (string) file_get_contents(resource_path('js/components/HelpModal.tsx'));
        $this->assertSame(1, preg_match("/const CHANGELOG = \[\s*\{\s*version: 'v(\d+\.\d+\.\d+)'/", $source, $m));

        $this->assertSame($m[1], AppVersion::current());
        $this->assertSame($m[1], ProjectArchive::appVersion());
        $this->assertNull(AppVersion::normalize('1.48.0-rc.1'));
        $this->assertSame('1.48.0', AppVersion::normalize('v1.48.0'));
    }
}
