<?php

namespace Tests\Feature\Profile;

use App\Jobs\ExportPersonalData;
use App\Models\AdminAuditLog;
use App\Models\Attachment;
use App\Models\CalendarConnection;
use App\Models\Capture;
use App\Models\Comment;
use App\Models\DataExport;
use App\Models\InstanceSettings;
use App\Models\Item;
use App\Models\Page;
use App\Models\PortalIdentity;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\DataExportReadyNotification;
use App\Notifications\NotificationCatalog;
use App\Support\Admin\UserDeletion;
use App\Support\Mail\EmailPreviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;
use ZipArchive;

/**
 * "Download my data" (GDPR Art. 15 + 20, BRAI-156 follow-up): the ZIP holds the person's own data
 * and nothing else — no secrets, no other people's comments or private pages — and the link is signed,
 * expiring and only for its owner; one request per hour.
 */
class DataExportTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $me;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
        $this->owner = User::findOrFail($this->workspace->owner_id);
        $this->me = $this->editor;
        $this->me->forceFill(['google_id' => 'GOOGLE-ID-777', 'remember_token' => 'REMEMBER-SECRET-1', 'bio' => 'I write the docs.', 'email_verified_at' => now()])->save();
    }

    protected function tearDown(): void
    {
        InstanceSettings::flushCache(); // a static cache of the (rolled-back) settings row
        parent::tearDown();
    }

    /** One of everything the archive carries, plus secrets and other people's data it must not. */
    private function seedPersonalData(): void
    {
        $mine = $this->item(['title' => 'My own issue', 'description' => '<p>MY-ISSUE-DESC</p>', 'created_by' => $this->me->id]);
        $this->item(['title' => 'Assigned to me', 'created_by' => $this->owner->id, 'assigned_to' => $this->me->id]);
        $this->item(['title' => 'OTHER-ISSUE-PRIVATE', 'description' => '<p>not mine</p>', 'created_by' => $this->owner->id]);
        $this->item(['title' => 'My trashed issue', 'created_by' => $this->me->id])->delete();

        Comment::create(['item_id' => $mine->id, 'user_id' => $this->me->id, 'body' => '<p>MY-COMMENT-BODY</p>']);
        Comment::create(['item_id' => $mine->id, 'user_id' => $this->owner->id, 'body' => '<p>OTHER-COMMENT-SECRET</p>']);

        Page::factory()->published()->create(['project_id' => $this->project->id, 'created_by' => $this->me->id, 'name' => 'My handbook', 'content' => ['type' => 'lexical', 'html' => '<p>MY-PAGE-TEXT</p>']]);
        Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->owner->id, 'name' => 'OTHER-PRIVATE-DRAFT', 'content' => ['type' => 'lexical', 'html' => '<p>secret plans</p>']]);
        Page::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->me->id, 'name' => 'My board', 'content' => ['type' => 'excalidraw', 'elements' => [['id' => 'e1', 'type' => 'rectangle', 'text' => 'MY-WHITEBOARD']], 'appState' => []]]);

        // a workspace the person left: its content stays with that workspace
        $other = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $otherProject = Project::factory()->create(['workspace_id' => $other->id, 'created_by' => $this->owner->id]);
        $otherPage = Page::factory()->published()->create(['project_id' => $otherProject->id, 'created_by' => $this->me->id, 'name' => 'LEFT-WORKSPACE-PAGE']);
        $this->assertNotNull($otherPage);

        Capture::factory()->create(['user_id' => $this->me->id, 'text' => 'MY-CAPTURE']);
        DB::table('time_entries')->insert(['workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'user_id' => $this->me->id, 'spent_on' => '2026-10-20', 'minutes' => 90, 'note' => 'MY-TIME-NOTE', 'created_at' => now(), 'updated_at' => now()]);

        $path = 'uploads/attachments/'.Str::uuid().'.pdf';
        Storage::disk('public')->put($path, 'MY-ATTACHMENT-BYTES');
        Attachment::create(['item_id' => $mine->id, 'user_id' => $this->me->id, 'filename' => basename($path), 'original_filename' => '../../Quarterly report.pdf', 'path' => $path, 'mime_type' => 'application/pdf', 'size' => 19]);

        CalendarConnection::factory()->create(['user_id' => $this->me->id, 'access_token' => 'CAL-ACCESS-SECRET', 'refresh_token' => 'CAL-REFRESH-SECRET', 'account_email' => 'me@calendar.test', 'server_url' => 'https://caldav.example.com/CALDAV-PRIVATE-PATH']);
        DB::table('calendar_feeds')->insert(['user_id' => $this->me->id, 'token_hash' => hash('sha256', 'FEED-TOKEN-SECRET'), 'token' => 'FEED-TOKEN-SECRET', 'options' => json_encode(['issues' => true]), 'fetch_count' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $this->me->updatePushSubscription('https://push.example.test/ENDPOINT-SECRET', 'PUBLICKEY-SECRET', 'AUTHKEY-SECRET');

        $client = (string) Str::uuid();
        DB::table('oauth_clients')->insert(['id' => $client, 'name' => 'Claude', 'secret' => 'CLIENT-SECRET-HASH', 'provider' => 'users', 'redirect_uris' => '[]', 'grant_types' => '["authorization_code"]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('oauth_access_tokens')->insert(['id' => 'ACCESS-TOKEN-ID-SECRET', 'user_id' => $this->me->id, 'client_id' => $client, 'name' => 'mcp', 'scopes' => '["mcp:use"]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addDays(30)]);

        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => $this->me->getMorphClass(), 'notifiable_id' => $this->me->id, 'data' => json_encode(['type' => 'item_commented', 'title' => 'Erin commented on BRAI-1', 'body' => 'OTHER-NOTIF-BODY', 'url' => 'https://app.test/x?signature=SIGNED-SECRET']), 'created_at' => now(), 'updated_at' => now()]);
        AdminAuditLog::create(['actor_id' => $this->owner->id, 'actor_name' => 'ADMIN-NAME <admin@x.test>', 'action' => 'user.email_verified', 'target_type' => 'user', 'target_id' => $this->me->id, 'target_label' => 'x', 'ip_address' => '203.0.113.99']);
        PortalIdentity::create(['email' => strtoupper($this->me->email), 'name' => 'Me as a guest', 'email_verified_at' => now()]);
        PortalIdentity::create(['email' => 'someone-else@example.test', 'name' => 'OTHER-GUEST']);
    }

    private function export(?User $as = null): DataExport
    {
        $this->actingAs($as ?? $this->me)->postJson(route('profile.data-export.store'))->assertStatus(202);

        return DataExport::latest('id')->firstOrFail();
    }

    /** @return array<string, string> path => contents */
    private function unzip(DataExport $export): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->file_path)));
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $files;
    }

    public function test_the_zip_has_the_persons_own_data_in_the_expected_files(): void
    {
        $this->seedPersonalData();
        $export = $this->export();

        $this->assertSame(DataExport::READY, $export->status);
        $this->assertTrue($export->expires_at->between(now()->addHours(23), now()->addHours(25)));
        $files = $this->unzip($export);

        foreach (['README.txt', 'manifest.json', 'account.json', 'memberships.json', 'issues.json', 'comments.json', 'page_comments.json',
            'client_portal_comments.json', 'pages.json', 'task_list_items.json', 'captures.json', 'snippet_collections.json', 'snippets.json',
            'bookmarks.json', 'bookmark_items.json', 'time_entries.json', 'my_day.json', 'sticky_notes.json', 'notifications.json',
            'devices.json', 'calendars.json', 'connected_apps.json', 'activity.json', 'admin_audit.json', 'guest_portals.json', 'uploads.json'] as $name) {
            $this->assertArrayHasKey($name, $files, $name);
            $this->assertNotNull(json_decode($files[$name], true) ?? ($name === 'README.txt' ? true : null), $name);
        }

        $account = json_decode($files['account.json'], true);
        $this->assertSame($this->me->email, $account['profile']['email']);
        $this->assertSame('I write the docs.', $account['profile']['bio']);
        $this->assertSame(['email_and_password', 'google'], $account['sign_in_methods']);
        $this->assertCount(count(NotificationCatalog::CATEGORIES), $account['notification_preferences']);

        $issues = collect(json_decode($files['issues.json'], true))->keyBy('title');
        $this->assertSame(['My own issue', 'Assigned to me', 'My trashed issue'], $issues->keys()->all());
        $this->assertSame(['creator', 'reporter'], $issues['My own issue']['your_roles']); // the creator is the default reporter
        $this->assertSame(['assignee'], $issues['Assigned to me']['your_roles']);
        $this->assertSame('MY-ISSUE-DESC', $issues['My own issue']['description_text']);
        $this->assertTrue($issues['My trashed issue']['in_trash']);
        $this->assertFalse($issues['My own issue']['in_trash']);

        $comments = json_decode($files['comments.json'], true);
        $this->assertSame(['MY-COMMENT-BODY'], array_column($comments, 'body_text'));

        $pages = collect(json_decode($files['pages.json'], true))->keyBy('name');
        $this->assertSame(['My handbook', 'My board'], $pages->keys()->all());
        $this->assertSame('MY-PAGE-TEXT', $pages['My handbook']['content_text']);
        $scene = json_decode($files[$pages['My board']['scene_file']], true);
        $this->assertSame('excalidraw', $scene['type']);
        $this->assertSame('MY-WHITEBOARD', $scene['elements'][0]['text']);

        $this->assertSame('MY-CAPTURE', json_decode($files['captures.json'], true)[0]['text']);
        $this->assertSame(90, json_decode($files['time_entries.json'], true)[0]['minutes']);

        $uploads = json_decode($files['uploads.json'], true);
        $this->assertTrue($uploads['files_included']);
        $file = $uploads['attachments'][0]['file'];
        $this->assertMatchesRegularExpression('#^files/attachments/\d+-quarterly-report\.pdf$#', $file); // no ../ from the original name
        $this->assertSame('MY-ATTACHMENT-BYTES', $files[$file]);

        $calendars = json_decode($files['calendars.json'], true);
        $this->assertSame('me@calendar.test', $calendars['connections'][0]['account_email']);
        $this->assertSame('caldav.example.com', $calendars['connections'][0]['server']);
        $this->assertSame(3, $calendars['calendar_feed'][0]['times_fetched']);

        $apps = json_decode($files['connected_apps.json'], true);
        $this->assertSame('Claude', $apps['app_access_tokens'][0]['app']);
        $this->assertSame(['mcp:use'], $apps['app_access_tokens'][0]['scopes']);

        $audit = json_decode($files['admin_audit.json'], true)['actions_on_your_account'];
        $this->assertSame('user.email_verified', $audit[0]['action']);
        $this->assertSame(['action', 'at'], array_keys($audit[0])); // not the admin's name or IP
        $guest = json_decode($files['guest_portals.json'], true);
        $this->assertSame(['Me as a guest'], array_column($guest['identities'], 'name'));

        $notifications = json_decode($files['notifications.json'], true);
        $this->assertSame('Erin commented on BRAI-1', $notifications[0]['title']);
        $this->assertSame('https://app.test/x', $notifications[0]['link']);

        $manifest = json_decode($files['manifest.json'], true);
        $this->assertSame('brainitive-personal-data', $manifest['format']);
        $this->assertSame(3, $manifest['counts']['issues.json']);
        $this->assertSame(1, $manifest['counts']['whiteboards']);
        $this->assertStringContainsString('issues.json', $files['README.txt']);
    }

    public function test_no_secrets_and_no_other_peoples_data_are_in_the_zip(): void
    {
        $this->seedPersonalData();
        $files = $this->unzip($this->export());
        $all = implode("\n", $files);

        foreach ([
            $this->me->getAuthPassword(), 'REMEMBER-SECRET-1', 'GOOGLE-ID-777', 'CAL-ACCESS-SECRET', 'CAL-REFRESH-SECRET', 'CALDAV-PRIVATE-PATH',
            'FEED-TOKEN-SECRET', hash('sha256', 'FEED-TOKEN-SECRET'), 'ENDPOINT-SECRET', 'PUBLICKEY-SECRET', 'AUTHKEY-SECRET', 'ACCESS-TOKEN-ID-SECRET',
            'CLIENT-SECRET-HASH', 'SIGNED-SECRET', 'OTHER-COMMENT-SECRET', 'OTHER-PRIVATE-DRAFT', 'secret plans', 'OTHER-ISSUE-PRIVATE', 'OTHER-NOTIF-BODY',
            'ADMIN-NAME', '203.0.113.99', 'OTHER-GUEST', 'someone-else@example.test', 'LEFT-WORKSPACE-PAGE', $this->owner->email,
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $all, "leaked: {$secret}");
        }
        foreach (['"password"', '"remember_token"', '"access_token"', '"refresh_token"', '"credentials"', '"token"', '"token_hash"', '"endpoint"', '"public_key"', '"auth_token"', '"secret"', '"payload"'] as $key) {
            $this->assertStringNotContainsString($key.':', $all, "secret field {$key}");
        }
    }

    public function test_unverified_accounts_get_no_guest_identities(): void
    {
        // even when the instance doesn't require verification (a linked identity could be someone else's address)
        InstanceSettings::instance()->update(['settings' => ['require_email_verification' => false]]);
        $this->assertTrue($this->me->fresh()->forceFill(['email_verified_at' => null])->hasVerifiedEmail()); // the relaxed check says "verified"
        $this->me->forceFill(['email_verified_at' => null])->save();
        PortalIdentity::create(['email' => $this->me->email, 'name' => 'Someone using my address', 'email_verified_at' => now()]);
        PortalIdentity::create(['email' => 'linked@example.test', 'name' => 'Linked to me', 'user_id' => $this->me->id]);

        $guest = json_decode($this->unzip($this->export())['guest_portals.json'], true);
        $this->assertSame([], $guest['identities']);

        $this->me->forceFill(['email_verified_at' => now()])->save();
        $this->travel(61)->minutes();
        $guest = json_decode($this->unzip($this->export())['guest_portals.json'], true);
        $this->assertSame(['Someone using my address', 'Linked to me'], array_column($guest['identities'], 'name'));
    }

    public function test_a_new_request_removes_the_earlier_file(): void
    {
        $first = $this->export();
        $this->travel(61)->minutes();
        $second = $this->export();

        $this->assertSame(DataExport::EXPIRED, $first->fresh()->status);
        Storage::disk('local')->assertMissing($first->file_path);
        Storage::disk('local')->assertExists($second->file_path);
    }

    public function test_an_account_deleted_before_or_while_the_job_runs_leaves_no_file(): void
    {
        $export = DataExport::create(['user_id' => $this->me->id, 'status' => DataExport::QUEUED]);
        $job = new ExportPersonalData($export);
        UserDeletion::delete($this->me); // the row cascades

        $job->handle();
        Storage::disk('local')->assertMissing($export->directory());

        // a run that died half-way: the folder goes even though the row is gone
        Storage::disk('local')->put($export->directory().'/work/issues.json', '[]');
        $job->failed(new RuntimeException('late'));
        Storage::disk('local')->assertMissing($export->directory());
        Notification::assertNothingSent();
    }

    public function test_the_owner_is_notified_with_a_signed_link_by_email_and_in_the_app(): void
    {
        $export = $this->export();

        Notification::assertSentTo($this->me, DataExportReadyNotification::class, function (DataExportReadyNotification $n) {
            $data = $n->toDatabase($this->me);

            return $data['type'] === 'data_export_ready'
                && str_contains($data['url'], '/data-exports/') && str_contains($data['url'], 'signature=')
                && in_array('mail', $n->via($this->me), true) && in_array('database', $n->via($this->me), true);
        });
        $this->assertSame('workspace', NotificationCatalog::category('data_export_ready'));
        $this->assertNotNull($export->fresh()->downloadUrl());

        $mail = EmailPreviews::render('data-export-ready');
        $this->assertStringContainsString('Your data is ready to download', $mail['html']);
        $this->assertStringContainsString('24 hours', $mail['text']);
    }

    public function test_download_is_signed_expiring_and_only_for_the_owner(): void
    {
        $export = $this->export();
        $url = $export->fresh()->downloadUrl();

        $this->actingAs($this->me)->get($url)->assertOk()->assertHeader('content-type', 'application/zip');
        $this->actingAs($this->me)->get($url, ['X-Inertia' => 'true'])->assertStatus(409); // Inertia::location → full download
        $this->actingAs($this->me)->get(route('profile.data-export.download', $export->uuid))->assertForbidden(); // unsigned
        $this->actingAs($this->owner)->get($url)->assertForbidden(); // a valid link, but not their data
        $this->actingAs($this->outsider)->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));

        $this->travel(25)->hours();
        $this->actingAs($this->me)->get($url)->assertRedirect(route('profile.edit').'#your-data');
        $this->artisan('schedule:test', ['--name' => 'exports:cleanup'])->assertSuccessful();
        $this->assertSame(DataExport::EXPIRED, $export->fresh()->status);
        Storage::disk('local')->assertMissing($export->file_path);
        $this->assertNull($export->fresh()->downloadUrl());
    }

    public function test_one_request_per_hour(): void
    {
        $this->export();

        $this->actingAs($this->me)->postJson(route('profile.data-export.store'))
            ->assertStatus(422)->assertJsonPath('errors.export.0', fn ($m) => str_contains($m, 'You can request a new copy in'));
        $this->assertSame(1, DataExport::count());
        $this->actingAs($this->me)->getJson(route('profile.data-export.index'))
            ->assertOk()->assertJsonCount(1, 'exports')->assertJsonPath('exports.0.status', 'ready')
            ->assertJsonPath('available_in', fn ($s) => $s > 3000);

        // another person is not affected
        $this->export($this->owner);

        $this->travel(61)->minutes();
        $this->export();
        $this->assertSame(2, DataExport::where('user_id', $this->me->id)->count());
        $this->assertTrue(RateLimiter::tooManyAttempts(DataExport::limiterKey($this->me), 1));
    }

    public function test_the_list_shows_only_the_persons_own_exports(): void
    {
        $this->export($this->owner);

        $this->actingAs($this->me)->getJson(route('profile.data-export.index'))->assertOk()->assertJsonCount(0, 'exports')->assertJsonPath('available_in', 0);
        $this->get(route('profile.data-export.index'))->assertOk();
        auth()->logout();
        $this->getJson(route('profile.data-export.index'))->assertUnauthorized();
        $this->postJson(route('profile.data-export.store'))->assertUnauthorized();
    }

    public function test_old_rows_are_pruned_and_deleting_the_account_removes_the_files(): void
    {
        $old = $this->export();
        $this->travel(31)->days();
        $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();
        $this->assertModelMissing($old);
        Storage::disk('local')->assertMissing($old->directory());

        $fresh = $this->export();
        Storage::disk('local')->assertExists($fresh->file_path);
        UserDeletion::delete($this->me);
        Storage::disk('local')->assertMissing($fresh->file_path);
        $this->assertSame(0, DataExport::count());
    }

    public function test_a_failed_job_cleans_up_and_tells_the_person(): void
    {
        $export = DataExport::create(['user_id' => $this->me->id, 'status' => DataExport::RUNNING]);
        RateLimiter::hit(DataExport::limiterKey($this->me), 3600);
        Storage::disk('local')->put($export->directory().'/partial.zip', 'x');

        (new ExportPersonalData($export))->failed(new RuntimeException('boom'));

        $this->assertSame(DataExport::FAILED, $export->fresh()->status);
        Storage::disk('local')->assertMissing($export->directory().'/partial.zip');
        $this->assertStringContainsString('Try again', $export->fresh()->present()['error']);
        $this->assertFalse(RateLimiter::tooManyAttempts(DataExport::limiterKey($this->me), 1)); // they may try again right away
    }

    public function test_the_profile_page_has_the_your_data_section(): void
    {
        $this->withoutVite();
        $this->actingAs($this->me)->get(route('profile.edit'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Profile/Edit'));
        $this->assertStringContainsString('<DownloadDataForm', (string) file_get_contents(resource_path('js/Pages/Profile/Edit.tsx')));
    }

    public function test_a_workspace_admin_gets_their_content_from_projects_they_are_not_a_member_of(): void
    {
        $this->item(['title' => 'Owner wrote this', 'created_by' => $this->owner->id]);
        $this->project->users()->detach($this->owner->id);

        $issues = json_decode($this->unzip($this->export($this->owner))['issues.json'], true);

        $this->assertSame(['Owner wrote this'], array_column($issues, 'title'));
    }

    public function test_a_second_request_while_one_is_being_prepared_is_refused(): void
    {
        DataExport::create(['user_id' => $this->me->id, 'status' => DataExport::QUEUED]);

        $this->actingAs($this->me)->postJson(route('profile.data-export.store'))
            ->assertStatus(422)->assertJsonPath('errors.export.0', 'Your data is already being prepared.');
        $this->assertFalse(RateLimiter::tooManyAttempts(DataExport::limiterKey($this->me), 1)); // not counted
    }

    public function test_items_of_a_project_the_person_was_removed_from_are_left_out(): void
    {
        $item = $this->item(['title' => 'Before I was removed', 'created_by' => $this->me->id]);
        DB::table('time_entries')->insert(['workspace_id' => $this->workspace->id, 'project_id' => $this->project->id, 'user_id' => $this->me->id, 'spent_on' => '2026-10-20', 'minutes' => 30, 'created_at' => now(), 'updated_at' => now()]);
        $this->project->users()->detach($this->me->id);

        $files = $this->unzip($this->export());

        $this->assertSame([], json_decode($files['issues.json'], true));
        $this->assertSame([], json_decode($files['time_entries.json'], true));
        $this->assertTrue(Item::whereKey($item->id)->exists());
    }
}
