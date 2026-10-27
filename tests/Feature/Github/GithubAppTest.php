<?php

namespace Tests\Feature\Github;

use App\Http\Controllers\ProjectGithubController;
use App\Models\ActivityLog;
use App\Models\AdminAuditLog;
use App\Models\Board;
use App\Models\GithubApp;
use App\Models\GithubAppDelivery;
use App\Models\GithubConnection;
use App\Models\GithubInstallation;
use App\Models\GithubRepository;
use App\Models\InstanceAdmin;
use App\Models\Item;
use App\Models\ItemDevLink;
use App\Models\Project;
use App\Models\User;
use App\Notifications\GithubSyncFailedNotification;
use App\Support\Github\GithubApi;
use App\Support\Github\GithubAppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * GitHub App connect + merge transitions (v1.42.0, BRAI-61). Every GitHub API call is faked
 * (Http::fake); the app's private key is a throwaway RSA key.
 */
class GithubAppTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private const INSTALLATION = 555;

    private const SECRET = 'app-webhook-secret';

    private static ?string $pem = null;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->admin = $this->projectMember('admin');
    }

    private static function pem(): string
    {
        if (self::$pem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            self::$pem = $pem;
        }

        return self::$pem;
    }

    private function configureApp(): void
    {
        config(['services.github_app' => [
            'app_id' => '99', 'slug' => 'brainitive-test', 'client_id' => 'Iv1.test', 'client_secret' => 'client-secret',
            'private_key' => self::pem(), 'webhook_secret' => self::SECRET, 'fake' => false,
        ]]);
    }

    /**
     * @param  list<int>  $userInstallations  installations the person's GitHub account can see
     * @param  list<int>|null  $userRepos  repository ids of the installation the person can read (null = all)
     */
    private function fakeGithub(array $userInstallations = [self::INSTALLATION], ?array $repos = null, array $extra = [], ?array $userRepos = null): void
    {
        $repos ??= [['id' => 1001, 'full_name' => 'acme/app', 'private' => true], ['id' => 1002, 'full_name' => 'acme/docs', 'private' => false]];
        $userRepos ??= array_column($repos, 'id');
        Http::fake($extra + [
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_user', 'token_type' => 'bearer']),
            'api.github.com/user/installations/*/repositories*' => Http::response(['repositories' => array_values(array_filter($repos, fn ($r) => in_array($r['id'], $userRepos, true)))]),
            'api.github.com/user/installations*' => Http::response(['installations' => array_map(fn ($id) => ['id' => $id], $userInstallations)]),
            'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_installation_token', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            'api.github.com/app/installations/*' => Http::response(['id' => self::INSTALLATION, 'account' => ['login' => 'acme', 'type' => 'Organization'], 'repository_selection' => 'selected', 'suspended_at' => null]),
            'api.github.com/installation/repositories*' => Http::response(['repositories' => $repos]),
        ]);
    }

    private function routes(string $name, ?Project $project = null): string
    {
        $project ??= $this->project;

        return route($name, [$project->workspace->slug, $project->slug]);
    }

    /** Connect GitHub as $user and return the setup response. */
    private function connect(?User $user = null, int $installationId = self::INSTALLATION, ?Project $project = null): TestResponse
    {
        $user ??= $this->admin;
        $location = $this->actingAs($user)->post($this->routes('projects.github.app.connect', $project))->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/apps/brainitive-test/installations/new?state=', (string) $location);
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        return $this->actingAs($user)->get(route('github.app.setup', ['installation_id' => $installationId, 'setup_action' => 'install', 'code' => 'oauth-code', 'state' => $query['state']]));
    }

    /** An app connection following acme/app (1001) without going through the UI. */
    private function appConnection(?Project $project = null, int $installationId = self::INSTALLATION, int $repoId = 1001, string $repo = 'acme/app'): GithubConnection
    {
        $project ??= $this->project;
        $installation = GithubInstallation::firstOrCreate(['installation_id' => $installationId], ['account_login' => 'acme', 'repositories' => [['id' => $repoId, 'full_name' => $repo, 'private' => true]]]);
        $connection = $project->githubConnection()->create(['uuid' => (string) Str::uuid(), 'secret' => 'unused', 'mode' => 'app', 'github_installation_id' => $installation->id, 'allowed_repository_ids' => [$repoId], 'created_by' => $this->admin->id]);
        GithubRepository::create(['github_connection_id' => $connection->id, 'github_installation_id' => $installation->id, 'repo_id' => $repoId, 'full_name' => $repo, 'private' => true]);

        return $connection;
    }

    private function deliver(string $event, array $payload, ?string $id = null, ?string $secret = null): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', route('webhooks.github.app'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $id ?? (string) Str::uuid(),
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? self::SECRET),
        ], $body);
    }

    private function repoPayload(array $payload, int $repoId = 1001, string $repo = 'acme/app', int $installationId = self::INSTALLATION): array
    {
        return $payload + ['installation' => ['id' => $installationId], 'repository' => ['id' => $repoId, 'full_name' => $repo]];
    }

    private function pr(Item $item, int $number, array $extra = [], string $action = 'opened'): array
    {
        return $this->repoPayload(['action' => $action, 'pull_request' => $extra + [
            'number' => $number, 'title' => "{$item->key}: change {$number}", 'html_url' => "https://github.com/acme/app/pull/{$number}",
            'head' => ['ref' => 'feat/x-'.$number], 'base' => ['ref' => 'main'], 'user' => ['login' => 'dev'],
            'state' => 'open', 'draft' => false, 'merged' => false, 'merged_at' => null, 'updated_at' => now()->toIso8601String(),
        ]]);
    }

    private function merged(Item $item, int $number): array
    {
        return $this->pr($item, $number, ['state' => 'closed', 'merged' => true, 'merged_at' => now()->toIso8601String()], 'closed');
    }

    private function setTransition(?string $columnSlug): void
    {
        $this->actingAs($this->admin)->put($this->routes('projects.github.transitions'), ['transitions' => [
            ['board_id' => $this->board->id, 'column_id' => $columnSlug ? $this->column($columnSlug) : null],
        ]])->assertSessionHasNoErrors()->assertRedirect();
        $this->board->refresh();
    }

    // ── Instance admin: the manifest flow ────────────────────────────────────

    public function test_a_refused_manifest_conversion_shows_and_logs_githubs_reason(): void
    {
        $instanceAdmin = User::factory()->create();
        InstanceAdmin::create(['user_id' => $instanceAdmin->id]);
        $start = $this->actingAs($instanceAdmin)->postJson(route('admin.github-app.manifest'), ['name' => 'Brainitive test'])->assertOk();
        parse_str((string) parse_url($start->json('url'), PHP_URL_QUERY), $query);
        Http::fake(['api.github.com/app-manifests/one-time-code/conversions' => Http::response([
            'message' => 'Validation failed',
            'errors' => [['resource' => 'Integration', 'field' => 'name', 'code' => 'custom', 'message' => 'Name has already been taken']],
        ], 422)]);
        Log::spy();

        $this->actingAs($instanceAdmin)->get(route('admin.github-app.callback', ['code' => 'one-time-code', 'state' => $query['state']]))
            ->assertRedirect(route('admin.github-app.show'))
            ->assertSessionHas('error', 'GitHub could not create the app (HTTP 422): Validation failed — name: Name has already been taken');

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'GitHub App manifest conversion refused' && $context['status'] === 422);
        $this->assertSame(0, GithubApp::count());
    }

    public function test_instance_admin_creates_the_app_with_the_manifest_flow(): void
    {
        $instanceAdmin = User::factory()->create();
        InstanceAdmin::create(['user_id' => $instanceAdmin->id]);

        $this->actingAs($this->admin)->get(route('admin.github-app.show'))->assertForbidden();
        $this->actingAs($instanceAdmin)->get(route('admin.github-app.show'))->assertOk();

        $start = $this->actingAs($instanceAdmin)->postJson(route('admin.github-app.manifest'), ['name' => 'Brainitive test', 'organization' => 'acme', 'public' => true])->assertOk();
        $this->assertStringStartsWith('https://github.com/organizations/acme/settings/apps/new?state=', $start->json('url'));
        $manifest = json_decode($start->json('manifest'), true);
        $this->assertSame(route('webhooks.github.app'), $manifest['hook_attributes']['url']);
        $this->assertSame([route('github.app.setup')], $manifest['callback_urls']);
        $this->assertTrue($manifest['request_oauth_on_install']);
        // GitHub refuses a setup URL / redirect-on-update together with OAuth on install (HTTP 422)
        $this->assertArrayNotHasKey('setup_url', $manifest);
        $this->assertArrayNotHasKey('setup_on_update', $manifest);
        $this->assertSame(['metadata' => 'read', 'contents' => 'read', 'issues' => 'read', 'pull_requests' => 'read', 'deployments' => 'read'], $manifest['default_permissions']);
        parse_str((string) parse_url($start->json('url'), PHP_URL_QUERY), $query);

        // a forged / foreign state creates nothing
        $this->actingAs($instanceAdmin)->get(route('admin.github-app.callback', ['code' => 'c', 'state' => 'forged']))->assertRedirect(route('admin.github-app.show'));
        $this->assertSame(0, GithubApp::count());

        // the state was spent by the forged attempt: start again
        $start = $this->actingAs($instanceAdmin)->postJson(route('admin.github-app.manifest'), ['name' => 'Brainitive test'])->assertOk();
        parse_str((string) parse_url($start->json('url'), PHP_URL_QUERY), $query);

        Http::fake(['api.github.com/app-manifests/one-time-code/conversions' => Http::response([
            'id' => 4321, 'slug' => 'brainitive-test', 'name' => 'Brainitive test', 'owner' => ['login' => 'acme'], 'html_url' => 'https://github.com/apps/brainitive-test',
            'client_id' => 'Iv1.abc', 'client_secret' => 'the-client-secret', 'webhook_secret' => 'the-webhook-secret', 'pem' => self::pem(),
        ], 201)]);

        $this->actingAs($instanceAdmin)->get(route('admin.github-app.callback', ['code' => 'one-time-code', 'state' => $query['state']]))
            ->assertRedirect(route('admin.github-app.show'))->assertSessionHas('success');

        $app = GithubApp::sole();
        $this->assertSame([4321, 'brainitive-test', 'the-webhook-secret'], [$app->app_id, $app->slug, $app->webhook_secret]);
        $raw = (array) DB::table('github_apps')->first();
        foreach (['client_secret', 'private_key', 'webhook_secret'] as $column) {
            $this->assertStringNotContainsString('the-', (string) $raw[$column]); // encrypted at rest
        }
        $this->assertArrayNotHasKey('private_key', $app->toArray());
        $this->assertSame('github_app.created', AdminAuditLog::latest('id')->value('action'));

        $current = GithubAppConfig::current();
        $this->assertSame(['stored', 4321, 'Iv1.abc'], [$current->source, $current->appId, $current->clientId]);

        // the admin page never carries a secret
        $page = $this->actingAs($instanceAdmin)->get(route('admin.github-app.show'))->assertOk()->getContent();
        $this->assertStringNotContainsString('the-webhook-secret', $page);
        $this->assertStringNotContainsString('the-client-secret', $page);
        $this->assertStringNotContainsString('PRIVATE KEY', $page);

        $this->actingAs($instanceAdmin)->delete(route('admin.github-app.destroy'))->assertRedirect();
        $this->assertSame(0, GithubApp::count());
        $this->assertNull(GithubAppConfig::current());
    }

    public function test_env_vars_override_the_stored_app(): void
    {
        GithubApp::create(['app_id' => 1, 'slug' => 'stored-app', 'private_key' => self::pem(), 'webhook_secret' => 'stored', 'client_id' => 'x', 'client_secret' => 'y']);
        $this->assertSame('stored-app', GithubAppConfig::current()->slug);

        $this->configureApp();
        $this->assertSame(['env', 'brainitive-test', 99], [GithubAppConfig::current()->source, GithubAppConfig::current()->slug, GithubAppConfig::current()->appId]);

        // a PEM with literal \n escapes (as in a one-line .env value) works too
        config(['services.github_app.private_key' => str_replace("\n", '\n', self::pem())]);
        $this->assertNotFalse(openssl_pkey_get_private(GithubAppConfig::current()->privateKey));
    }

    public function test_app_jwt_and_installation_tokens_are_cached_encrypted(): void
    {
        $this->configureApp();
        $this->fakeGithub();
        $api = GithubApi::make();

        [$header, $payload, $signature] = explode('.', $api->jwt());
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame('99', $claims['iss']);
        $this->assertLessThanOrEqual(600, $claims['exp'] - $claims['iat']);
        $public = openssl_pkey_get_details(openssl_pkey_get_private(self::pem()))['key'];
        $this->assertSame(1, openssl_verify("{$header}.{$payload}", base64_decode(strtr($signature, '-_', '+/').'=='), $public, OPENSSL_ALGO_SHA256));

        $this->assertSame('ghs_installation_token', $api->installationToken(self::INSTALLATION));
        $this->assertSame('ghs_installation_token', $api->installationToken(self::INSTALLATION));
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->header('Authorization')[0] ?? '', 'Bearer '.$header.'.'));
        $this->assertStringNotContainsString('ghs_installation_token', (string) Cache::get('github-app:installation-token:99:'.self::INSTALLATION));
    }

    // ── Project admin: connect, verify ownership, pick repositories ─────────

    public function test_connect_verifies_the_installation_and_lists_its_repositories(): void
    {
        $this->configureApp();
        $this->fakeGithub();

        $this->actingAs($this->editor)->post($this->routes('projects.github.app.connect'))->assertForbidden();

        $this->connect()->assertRedirect($this->routes('projects.edit').'?tab=integrations')->assertSessionHas('success');

        $connection = $this->project->githubConnection()->sole();
        $this->assertSame('app', $connection->mode);
        $installation = $connection->installation;
        $this->assertSame([self::INSTALLATION, 'acme', 2], [$installation->installation_id, $installation->account_login, count($installation->repositories)]);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.github.com/user/installations?per_page=100&page=1' && $r->header('Authorization')[0] === 'Bearer gho_user');

        // settings list the installation's repositories (no tokens, no app secrets)
        $page = $this->actingAs($this->admin)->get($this->routes('projects.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('acme\/docs', $page);
        $this->assertStringNotContainsString('ghs_installation_token', $page);
        $this->assertStringNotContainsString('gho_user', $page);
        $this->assertStringNotContainsString(self::SECRET, $page);
        $this->assertStringNotContainsString('client-secret', $page);
    }

    public function test_setup_refuses_foreign_installations_and_foreign_or_spent_states(): void
    {
        $this->configureApp();
        $this->fakeGithub(userInstallations: [777]); // the person's GitHub account can't see 555

        $this->connect()->assertSessionHas('error', 'That GitHub installation isn’t available to your GitHub account.');
        $this->assertNull($this->project->githubConnection()->first());
        $this->assertSame(0, GithubInstallation::count());

        // a state started by someone else (or never) is refused
        $location = $this->actingAs($this->admin)->post($this->routes('projects.github.app.connect'))->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $otherAdmin = $this->projectMember('admin');
        $this->actingAs($otherAdmin)->get(route('github.app.setup', ['installation_id' => 777, 'code' => 'c', 'state' => $query['state']]))
            ->assertRedirect(route('dashboard'))->assertSessionHas('error');
        $this->actingAs($this->admin)->get(route('github.app.setup', ['installation_id' => 777, 'code' => 'c', 'state' => 'never-started']))
            ->assertRedirect(route('dashboard'));

        // the right person, but they lost admin rights meanwhile
        $location = $this->actingAs($this->admin)->post($this->routes('projects.github.app.connect'))->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $this->project->users()->updateExistingPivot($this->admin->id, ['role' => 'editor']);
        $this->actingAs($this->admin)->get(route('github.app.setup', ['installation_id' => 777, 'code' => 'c', 'state' => $query['state']]))->assertForbidden();
        $this->assertNull($this->project->githubConnection()->first());
    }

    public function test_only_repositories_the_person_can_read_are_offered(): void
    {
        // an outside collaborator on acme/docs sees the org's installation in /user/installations,
        // but must never see or claim the org's private acme/app
        $this->configureApp();
        $this->fakeGithub(userRepos: [1002]);
        $this->connect()->assertSessionHas('success');
        $this->assertSame([1002], $this->project->githubConnection->allowed_repository_ids);

        $page = $this->actingAs($this->admin)->get($this->routes('projects.edit'))->getContent();
        $this->assertStringContainsString('acme\/docs', $page);
        $this->assertStringNotContainsString('acme\/app', $page);

        $this->actingAs($this->admin)->from($this->routes('projects.edit'))->put($this->routes('projects.github.repositories'), ['repository_ids' => [1001]])
            ->assertSessionHasErrors('repository_ids');
        $this->actingAs($this->admin)->put($this->routes('projects.github.repositories'), ['repository_ids' => [1002]])->assertSessionHasNoErrors();
        $this->assertSame(['acme/docs'], GithubRepository::pluck('full_name')->all());
    }

    public function test_back_from_sharing_more_repositories_on_github_rereads_the_list(): void
    {
        $this->configureApp();
        $this->fakeGithub(repos: [['id' => 1001, 'full_name' => 'acme/app', 'private' => true], ['id' => 1003, 'full_name' => 'acme/new', 'private' => false]]);
        $this->appConnection();
        $update = ['installation_id' => self::INSTALLATION, 'setup_action' => 'update'];

        // someone who manages no project on that installation gets nothing
        $this->actingAs($this->editor)->get(route('github.app.setup', $update))->assertRedirect(route('dashboard'));
        $this->assertSame([1001], array_column(GithubInstallation::sole()->repositories, 'id'));

        $this->actingAs($this->admin)->get(route('github.app.setup', $update))
            ->assertRedirect($this->routes('projects.edit').'?tab=integrations')->assertSessionHas('success');
        $this->assertSame([1001, 1003], array_column(GithubInstallation::sole()->repositories, 'id'));
        $this->assertSame([1001], $this->project->githubConnection()->sole()->allowed_repository_ids); // no proof yet

        // with GitHub's authorization code, the repositories this person can read are offered too
        $this->actingAs($this->admin)->get(route('github.app.setup', $update + ['code' => 'oauth-code']))->assertSessionHas('success');
        $this->assertEqualsCanonicalizing([1001, 1003], $this->project->githubConnection()->sole()->allowed_repository_ids);
    }

    public function test_connect_without_an_app_or_client_secret_explains_why(): void
    {
        $this->actingAs($this->admin)->from($this->routes('projects.edit'))->post($this->routes('projects.github.app.connect'))
            ->assertRedirect($this->routes('projects.edit'))->assertSessionHas('error');

        $this->configureApp();
        config(['services.github_app.client_secret' => null]);
        $this->actingAs($this->admin)->from($this->routes('projects.edit'))->post($this->routes('projects.github.app.connect'))->assertSessionHas('error');
    }

    public function test_repositories_come_from_the_installation_and_map_to_one_project(): void
    {
        $this->configureApp();
        $this->fakeGithub();
        $this->connect();
        $put = fn (array $ids, ?Project $project = null, ?User $user = null) => $this->actingAs($user ?? $this->admin)
            ->from($this->routes('projects.edit', $project))->put($this->routes('projects.github.repositories', $project), ['repository_ids' => $ids]);

        $put([999])->assertSessionHasErrors('repository_ids'); // not shared with the installation
        $put([1001], user: $this->editor)->assertForbidden();
        $put([1001])->assertSessionHasNoErrors();
        $this->assertSame(['acme/app'], GithubRepository::pluck('full_name')->all());

        // a second project on the same installation: acme/app is taken, acme/docs is free
        $other = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id, 'key' => 'OTH']);
        $other->users()->attach($this->admin->id, ['role' => 'admin']);
        $this->connect(project: $other);
        $this->assertSame($this->project->githubConnection->github_installation_id, $other->githubConnection->github_installation_id);
        $put([1001], $other)->assertSessionHasErrors(['repository_ids' => 'acme/app is already connected to another project.']);
        $put([1002], $other)->assertSessionHasNoErrors();

        // unticking removes it
        $put([])->assertSessionHasNoErrors();
        $this->assertSame(['acme/docs'], GithubRepository::pluck('full_name')->all());
    }

    // ── The app webhook ──────────────────────────────────────────────────────

    public function test_app_webhook_verifies_dedupes_and_routes_to_the_following_project(): void
    {
        $issue = $this->item(['title' => 'Login']);
        $push = fn (int $repoId = 1001, int $installation = self::INSTALLATION) => $this->repoPayload(['ref' => 'refs/heads/main', 'commits' => [
            ['id' => str_repeat('a', 40), 'message' => "fix({$issue->key}): login", 'url' => 'https://github.com/acme/app/commit/a', 'author' => ['username' => 'dev'], 'timestamp' => now()->toIso8601String()],
        ]], $repoId, 'acme/app', $installation);

        $this->deliver('push', $push())->assertNotFound(); // no app configured
        $this->configureApp();
        $connection = $this->appConnection();

        $this->deliver('push', $push(), secret: 'wrong')->assertStatus(401);
        $this->deliver('issues', $push())->assertStatus(202)->assertJson(['message' => 'Ignored.']);

        $this->deliver('push', $push(), 'd-1')->assertStatus(202);
        $this->deliver('push', $push(), 'd-1')->assertOk()->assertJson(['message' => 'Already received.']);
        $this->assertSame(1, GithubAppDelivery::count());
        $this->assertSame(1, $issue->devLinks()->where('kind', 'commit')->count());
        $this->assertSame($connection->id, GithubAppDelivery::sole()->github_connection_id);
        $this->assertSame('push', $connection->fresh()->last_event);

        // a repository nobody follows, or the same repo id from another installation, never reaches the project
        $this->deliver('push', $push(2002), 'd-2')->assertStatus(202);
        $this->deliver('push', $push(1001, 999), 'd-3')->assertStatus(202);
        $this->assertSame(['ignored', 'ignored'], GithubAppDelivery::whereIn('delivery_id', ['d-2', 'd-3'])->pluck('status')->all());
        $this->assertSame(1, ItemDevLink::count());
    }

    public function test_uninstall_suspend_and_repository_removal_stop_syncing_and_notify_admins_once(): void
    {
        Notification::fake();
        $this->configureApp();
        $connection = $this->appConnection();
        $issue = $this->item();
        $installation = ['id' => self::INSTALLATION, 'account' => ['login' => 'acme', 'type' => 'Organization']];

        // repository removed from the installation
        $this->deliver('installation_repositories', ['action' => 'removed', 'installation' => $installation, 'repository_selection' => 'selected',
            'repositories_added' => [], 'repositories_removed' => [['id' => 1001, 'full_name' => 'acme/app']]])->assertStatus(202);
        $connection->refresh();
        $this->assertNotNull($connection->access_lost_at);
        $this->assertStringContainsString('acme/app was removed', $connection->last_error);
        $this->assertNotNull(GithubRepository::sole()->removed_at);
        Notification::assertSentTo($this->admin, GithubSyncFailedNotification::class, fn ($n) => $n->toDatabase($this->admin)['type'] === 'github_sync_failed');
        Notification::assertNotSentTo($this->editor, GithubSyncFailedNotification::class);

        // its events no longer arrive
        $this->deliver('pull_request', $this->pr($issue, 1))->assertStatus(202);
        $this->assertSame(0, ItemDevLink::count());

        // added back → syncing again, the error clears
        $this->deliver('installation_repositories', ['action' => 'added', 'installation' => $installation,
            'repositories_added' => [['id' => 1001, 'full_name' => 'acme/app', 'private' => true]], 'repositories_removed' => []]);
        $this->assertNull($connection->fresh()->access_lost_at);
        $this->assertNull(GithubRepository::sole()->removed_at);

        // uninstalled: one notification even if GitHub sends it twice
        $this->deliver('installation', ['action' => 'deleted', 'installation' => $installation]);
        $this->deliver('installation', ['action' => 'deleted', 'installation' => $installation]);
        $this->assertNotNull(GithubInstallation::sole()->removed_at);
        $this->assertStringContainsString('uninstalled from acme', $connection->fresh()->last_error);
        Notification::assertSentToTimes($this->admin, GithubSyncFailedNotification::class, 2); // repo removal + uninstall
        $this->deliver('pull_request', $this->pr($issue, 1));
        $this->assertSame(0, ItemDevLink::count());
    }

    public function test_suspend_and_unsuspend(): void
    {
        Notification::fake();
        $this->configureApp();
        $connection = $this->appConnection();
        $installation = ['id' => self::INSTALLATION, 'account' => ['login' => 'acme']];

        $this->deliver('installation', ['action' => 'suspend', 'installation' => $installation]);
        $this->assertStringContainsString('suspended', $connection->fresh()->last_error);
        $this->deliver('installation', ['action' => 'unsuspend', 'installation' => $installation]);
        $this->assertNull($connection->fresh()->last_error);
        Notification::assertSentToTimes($this->admin, GithubSyncFailedNotification::class, 1);
    }

    // ── Merge transitions ────────────────────────────────────────────────────

    public function test_merging_the_primary_pr_moves_the_card_once_as_github(): void
    {
        $this->configureApp();
        $this->appConnection();
        $issue = $this->item(['column_id' => $this->column('to-do')]);
        $this->setTransition('done');

        $this->deliver('pull_request', $this->pr($issue, 7));
        $this->assertSame($this->column('to-do'), $issue->fresh()->column_id);

        $this->deliver('pull_request', $this->merged($issue, 7), 'merge-1')->assertStatus(202);
        $issue->refresh();
        $this->assertSame($this->column('done'), $issue->column_id);
        $this->assertNotNull($issue->completed_at);
        $moved = ActivityLog::where('loggable_id', $issue->id)->where('action', 'moved')->sole();
        $this->assertSame(['via' => 'github'], $moved->metadata);
        $this->assertNull($moved->user_id);

        // someone moves it back; a redelivery / retry of the merge never moves it again
        $issue->update(['column_id' => $this->column('in-progress'), 'completed_at' => null]);
        $this->deliver('pull_request', $this->merged($issue, 7), 'merge-2');
        $this->assertSame($this->column('in-progress'), $issue->fresh()->column_id);
    }

    public function test_close_without_merge_and_secondary_prs_do_nothing(): void
    {
        $this->configureApp();
        $this->appConnection();
        $issue = $this->item(['column_id' => $this->column('to-do')]);
        $this->setTransition('done');

        $this->deliver('pull_request', $this->pr($issue, 1)); // primary: the first linked PR
        $this->deliver('pull_request', $this->pr($issue, 2));
        $this->deliver('pull_request', $this->pr($issue, 1, ['state' => 'closed'], 'closed'));
        $this->assertSame('closed', ItemDevLink::where('identifier', '1')->value('state'));
        $this->deliver('pull_request', $this->merged($issue, 2));
        $this->assertSame($this->column('to-do'), $issue->fresh()->column_id);

        // picking another primary PR on the card: its merge moves the card
        $this->deliver('pull_request', $this->pr($issue, 3));
        $third = ItemDevLink::where('identifier', '3')->sole();
        $url = $this->itemRoute('boards.items.development.primary', $issue);
        $this->actingAs($this->viewer)->patchJson($url, ['link_id' => $third->id])->assertForbidden();
        $this->actingAs($this->editor)->patchJson($url, ['link_id' => 999999])->assertStatus(422);
        $response = $this->actingAs($this->editor)->patchJson($url, ['link_id' => $third->id])->assertOk()
            ->assertJsonPath('development.merge_column', 'Done');
        $this->assertSame(['3'], collect($response->json('development.pull_requests'))->where('primary', true)->pluck('identifier')->all());

        $this->deliver('pull_request', $this->merged($issue, 3));
        $this->assertSame($this->column('done'), $issue->fresh()->column_id);
    }

    public function test_merge_never_reopens_a_done_card_and_is_off_by_default(): void
    {
        $this->configureApp();
        $this->appConnection();
        $done = $this->item(['column_id' => $this->column('done'), 'completed_at' => now()]);
        $todo = $this->item(['column_id' => $this->column('to-do')]);

        // off by default
        $this->deliver('pull_request', $this->merged($todo, 1));
        $this->assertSame($this->column('to-do'), $todo->fresh()->column_id);

        $this->setTransition('in-progress');
        $this->deliver('pull_request', $this->merged($done, 2));
        $this->assertSame($this->column('done'), $done->fresh()->column_id);
        $this->assertNotNull($done->fresh()->completed_at);

        // turning it off again
        $this->setTransition(null);
        $this->assertArrayNotHasKey('github', $this->board->settings ?? []);
    }

    public function test_transitions_are_validated_per_board(): void
    {
        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id]);
        $foreignBoard = Board::factory()->create(['project_id' => $otherProject->id]);
        $foreignColumn = $foreignBoard->columns()->first();
        $url = $this->routes('projects.github.transitions');

        $this->actingAs($this->editor)->put($url, ['transitions' => [['board_id' => $this->board->id, 'column_id' => $this->column('done')]]])->assertForbidden();
        $this->actingAs($this->admin)->put($url, ['transitions' => [['board_id' => $foreignBoard->id, 'column_id' => $foreignColumn->id]]])->assertSessionHasErrors('transitions.0.board_id');
        $this->actingAs($this->admin)->put($url, ['transitions' => [['board_id' => $this->board->id, 'column_id' => $foreignColumn->id]]])->assertSessionHasErrors('transitions.0.column_id');
        $this->assertNull($this->board->fresh()->settings['github'] ?? null);
    }

    public function test_merge_transitions_work_for_legacy_webhook_connections_too(): void
    {
        $connection = $this->project->githubConnection()->create(['uuid' => (string) Str::uuid(), 'secret' => 'legacy']);
        $issue = $this->item(['column_id' => $this->column('to-do')]);
        $this->setTransition('done');
        $body = json_encode($this->merged($issue, 4));

        $this->call('POST', route('webhooks.github', $connection->uuid), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'pull_request', 'HTTP_X_GITHUB_DELIVERY' => 'legacy-1',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'legacy'),
        ], $body)->assertStatus(202);

        $this->assertSame($this->column('done'), $issue->fresh()->column_id);
    }

    // ── Refresh ──────────────────────────────────────────────────────────────

    public function test_refresh_catches_up_missed_merges_and_backs_off_on_rate_limits(): void
    {
        $this->configureApp();
        $connection = $this->appConnection();
        $issue = $this->item(['column_id' => $this->column('to-do')]);
        $this->setTransition('done');
        $this->deliver('pull_request', $this->pr($issue, 9));

        $reset = now()->addMinutes(20)->getTimestamp();
        $limited = false;
        $merged = $this->merged($issue, 9)['pull_request'];
        Http::fake(function (HttpRequest $r) use (&$limited, $reset, $merged) {
            return match (true) {
                $limited => Http::response(['message' => 'API rate limit exceeded'], 403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) $reset]),
                str_ends_with($r->url(), '/access_tokens') => Http::response(['token' => 'ghs_installation_token', 'expires_at' => now()->addHour()->toIso8601String()], 201),
                str_contains($r->url(), '/installation/repositories') => Http::response(['repositories' => [['id' => 1001, 'full_name' => 'acme/app', 'private' => true]]]),
                str_ends_with($r->url(), '/repos/acme/app/pulls/9') => Http::response($merged),
                default => Http::response(['message' => 'Not Found'], 404),
            };
        });

        // the merge delivery was missed; refresh reads the PR from GitHub
        $this->actingAs($this->editor)->post($this->routes('projects.github.refresh'))->assertForbidden();
        $this->actingAs($this->admin)->post($this->routes('projects.github.refresh'))->assertRedirect()->assertSessionHas('success');
        $this->assertSame('merged', ItemDevLink::sole()->state);
        $this->assertSame($this->column('done'), $issue->fresh()->column_id);
        $this->assertNotNull($connection->fresh()->last_refreshed_at);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/pulls/9') && $r->header('Authorization')[0] === 'Bearer ghs_installation_token');

        // rate limited: remember until when, and don't call GitHub again before then
        $this->deliver('pull_request', $this->pr($issue, 10));
        Cache::flush();
        $limited = true;
        $before = count(Http::recorded());
        $this->actingAs($this->admin)->post($this->routes('projects.github.refresh'));
        $connection->refresh();
        $this->assertSame($reset, $connection->rate_limited_until->getTimestamp());
        $this->assertStringContainsString('rate limit', $connection->last_error);
        $this->assertCount($before + 1, Http::recorded());

        $this->actingAs($this->admin)->post($this->routes('projects.github.refresh'))->assertSessionHas('error');
        $this->assertCount($before + 1, Http::recorded());

        // the card never shows a token
        $page = $this->actingAs($this->admin)->get($this->routes('projects.edit'))->getContent();
        $this->assertStringNotContainsString('ghs_installation_token', $page);
    }

    public function test_refresh_notices_repositories_the_installation_no_longer_shares(): void
    {
        Notification::fake();
        $this->configureApp();
        $connection = $this->appConnection();
        $this->fakeGithub(repos: [['id' => 1002, 'full_name' => 'acme/docs', 'private' => false]]);

        $this->actingAs($this->admin)->post($this->routes('projects.github.refresh'));

        $this->assertNotNull(GithubRepository::sole()->removed_at);
        $this->assertStringContainsString('acme/app is no longer shared', $connection->fresh()->last_error);
        Notification::assertSentTo($this->admin, GithubSyncFailedNotification::class);
    }

    // ── Legacy ───────────────────────────────────────────────────────────────

    public function test_legacy_connections_keep_working_and_can_switch_to_the_app(): void
    {
        $legacy = $this->project->githubConnection()->create(['uuid' => (string) Str::uuid(), 'secret' => 'legacy-secret']);
        $issue = $this->item();
        $sign = fn (string $body, string $secret) => 'sha256='.hash_hmac('sha256', $body, $secret);
        $body = json_encode(['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/app'], 'commits' => [
            ['id' => str_repeat('b', 40), 'message' => "fix({$issue->key}): x", 'timestamp' => now()->toIso8601String()],
        ]]);
        $post = fn (string $id, string $secret) => $this->call('POST', route('webhooks.github', $legacy->uuid), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'push', 'HTTP_X_GITHUB_DELIVERY' => $id, 'HTTP_X_HUB_SIGNATURE_256' => $sign($body, $secret),
        ], $body);

        $post('l-1', 'legacy-secret')->assertStatus(202);
        $this->assertSame(1, $issue->devLinks()->count());

        $settings = ProjectGithubController::settingsFor($this->project);
        $this->assertSame(['webhook', 'legacy-secret'], [$settings['connection']['mode'], $settings['connection']['secret']]);
        $this->assertNull($settings['app']);

        // switch: same connection, links kept, the manual hooks stop
        $this->configureApp();
        $this->fakeGithub();
        $this->connect()->assertSessionHas('success');
        $legacy->refresh();
        $this->assertSame('app', $legacy->mode);
        $this->assertNotSame('legacy-secret', $legacy->secret);
        $this->assertSame(1, $issue->devLinks()->count());
        $post('l-2', 'legacy-secret')->assertStatus(401);
        $this->assertSame(1, GithubConnection::count());
    }
}
