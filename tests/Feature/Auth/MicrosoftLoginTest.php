<?php

namespace Tests\Feature\Auth;

use App\Models\InstanceSettings;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\Auth\MicrosoftLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Sign in with Microsoft (BRAI-148): the OIDC flow (state + nonce + PKCE), id_token checks, and
 * the account rules shared with Google / GitHub — above all, which emails may link an account.
 * The token endpoint is faked; stray requests fail the test.
 */
class MicrosoftLoginTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    private const WORK_TENANT = '72f988bf-86f1-41af-91ab-2d7cd011db47';

    private const OID = '00000000-0000-0000-66f3-3332eca7ea81';

    /** What the faked token endpoint answers: [json body, status]. */
    private array $tokenAnswer = [[], 200];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Http::fake(['login.microsoftonline.com/*' => fn () => Http::response(...$this->tokenAnswer)]);
        InstanceSettings::flushCache();
        config(['services.microsoft' => [
            'client_id' => 'client-123',
            'client_secret' => 'secret-xyz',
            'tenant' => 'common',
            'redirect' => 'https://app.test/calendar/connections/outlook/callback',
            'login_redirect' => 'https://app.test/login/microsoft/callback',
        ]]);
    }

    protected function tearDown(): void
    {
        InstanceSettings::flushCache();
        parent::tearDown();
    }

    // ── the redirect ────────────────────────────────────────────────────────────────────────

    public function test_the_redirect_carries_state_nonce_pkce_and_the_login_scopes(): void
    {
        $location = (string) $this->get(route('login.microsoft'))->assertRedirect()->headers->get('Location');
        $flow = session('auth_microsoft_oauth');

        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('client-123', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid profile email User.Read', $query['scope']);
        $this->assertSame('https://app.test/login/microsoft/callback', $query['redirect_uri']);
        $this->assertSame('query', $query['response_mode']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame($flow['state'], $query['state']);
        $this->assertSame($flow['nonce'], $query['nonce']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(MicrosoftLogin::challenge($flow['verifier']), $query['code_challenge']);
        $this->assertSame(43, strlen($query['code_challenge']));
        $this->assertStringNotContainsString('secret-xyz', $location);
        $this->assertStringNotContainsString($flow['verifier'], $location);
    }

    public function test_login_and_welcome_offer_microsoft_only_when_the_app_is_configured(): void
    {
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canLoginWithMicrosoft', true));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('canLoginWithMicrosoft', true));

        config(['services.microsoft.client_secret' => null]);
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canLoginWithMicrosoft', false));
        $this->get(route('login.microsoft'))->assertNotFound();
        $this->get(route('login.microsoft.callback', ['code' => 'x', 'state' => 'y']))->assertNotFound();
    }

    // ── refused callbacks ───────────────────────────────────────────────────────────────────

    public function test_a_callback_with_another_state_or_no_flow_is_refused_before_any_request(): void
    {
        $expired = 'The Microsoft sign-in expired. Please try again.';

        $this->get(route('login.microsoft.callback', ['code' => 'c', 'state' => 'anything']))
            ->assertRedirect(route('login'))->assertSessionHas('status', $expired);

        $this->get(route('login.microsoft'));
        $state = session('auth_microsoft_oauth')['state'];
        $this->get(route('login.microsoft.callback', ['code' => 'c', 'state' => 'x'.$state]))->assertSessionHas('status', $expired);
        // the failed attempt used the flow up: the right state no longer works either
        $this->get(route('login.microsoft.callback', ['code' => 'c', 'state' => $state]))->assertSessionHas('status', $expired);

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_cancelling_at_microsoft_comes_back_with_a_message(): void
    {
        $this->get(route('login.microsoft'));
        $this->get(route('login.microsoft.callback', ['error' => 'access_denied', 'state' => session('auth_microsoft_oauth')['state']]))
            ->assertRedirect(route('login'))->assertSessionHas('status', 'You cancelled signing in with Microsoft.');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_refused_code_exchange_signs_no_one_in(): void
    {
        $this->get(route('login.microsoft'));
        $this->tokenAnswer = [['error' => 'invalid_grant', 'error_description' => 'AADSTS70008'], 400];

        $this->get(route('login.microsoft.callback', ['code' => 'c', 'state' => session('auth_microsoft_oauth')['state']]))
            ->assertRedirect(route('login'))->assertSessionHas('status', 'Microsoft didn’t accept the sign-in. Please try again.');
        $this->assertGuest();
    }

    public function test_id_tokens_for_another_app_sign_in_or_tenant_are_refused(): void
    {
        $user = User::factory()->create(['microsoft_id' => self::WORK_TENANT.':'.self::OID]);
        $cases = [
            'another app' => ['aud' => 'someone-elses-client'],
            'another sign-in' => ['nonce' => 'replayed-nonce'],
            'issuer of another tenant' => ['iss' => 'https://login.microsoftonline.com/'.MicrosoftLogin::CONSUMER_TENANT.'/v2.0'],
            'expired' => ['exp' => time() - 3600],
            'no object id' => ['oid' => null],
            'not a tenant id' => ['tid' => 'evil', 'iss' => 'https://login.microsoftonline.com/evil/v2.0'],
        ];

        foreach ($cases as $case => $claims) {
            $this->signIn($claims)->assertRedirect(route('login'))
                ->assertSessionHas('status', 'Something went wrong signing in with Microsoft. Please try again.');
            $this->assertGuest();
        }
        $this->assertSame(self::WORK_TENANT.':'.self::OID, $user->fresh()->microsoft_id);

        $this->signIn()->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user); // the same token with the right claims is accepted
    }

    public function test_a_single_tenant_setup_refuses_other_tenants(): void
    {
        config(['services.microsoft.tenant' => '11111111-2222-3333-4444-555555555555']);
        User::factory()->create(['microsoft_id' => self::WORK_TENANT.':'.self::OID]);

        $this->signIn()->assertSessionHas('status', 'Something went wrong signing in with Microsoft. Please try again.');
        $this->assertGuest();
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/oauth2/v2.0/token');
    }

    // ── accounts ────────────────────────────────────────────────────────────────────────────

    public function test_a_personal_account_signs_up_with_its_verified_email(): void
    {
        $this->signIn(['tid' => MicrosoftLogin::CONSUMER_TENANT, 'email' => 'New.Person@Outlook.com', 'name' => 'New Person'])
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'new.person@outlook.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(MicrosoftLogin::CONSUMER_TENANT.':'.self::OID, $user->microsoft_id);
        $this->assertSame('New Person', $user->name);
        $this->assertNotNull($user->email_verified_at);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::TOKEN_URL
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'auth-code'
            && $request['client_id'] === 'client-123'
            && $request['client_secret'] === 'secret-xyz'
            && $request['redirect_uri'] === 'https://app.test/login/microsoft/callback'
            && strlen($request['code_verifier']) === 96);
    }

    public function test_a_linked_account_signs_in_without_any_email(): void
    {
        $user = User::factory()->create(['email' => 'linked@corp.example', 'microsoft_id' => self::WORK_TENANT.':'.self::OID]);

        $this->signIn(['email' => null])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_work_account_with_a_domain_verified_email_links_the_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'ada@corp.example']);

        $this->signIn(['email' => 'Ada@Corp.example', 'xms_edov' => true])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(self::WORK_TENANT.':'.self::OID, $user->fresh()->microsoft_id);
    }

    public function test_an_existing_mixed_case_address_is_linked_not_duplicated(): void
    {
        $user = User::factory()->create(['email' => 'Grace@Outlook.com']);

        $this->signIn(['tid' => MicrosoftLogin::CONSUMER_TENANT, 'email' => 'grace@outlook.com']);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
    }

    public function test_an_email_microsoft_has_not_verified_never_links_or_creates_an_account(): void
    {
        // nOAuth: any tenant admin can give their own user the victim's address
        $victim = User::factory()->create(['email' => 'victim@corp.example', 'password' => Hash::make('victim-pass')]);
        $message = 'We couldn’t confirm the email address of this Microsoft account. Sign in with your email and password, or ask your Microsoft 365 admin to verify your email domain.';

        foreach ([
            ['email' => 'victim@corp.example'],
            ['email' => 'victim@corp.example', 'xms_edov' => false],
            ['email' => null, 'preferred_username' => 'victim@corp.example'],
            ['email' => 'stranger@corp.example'],
            ['email' => 'victim@corp.example', 'xms_edov' => true, 'acct' => 1], // a guest of the attacker's tenant
        ] as $claims) {
            $this->signIn($claims)->assertRedirect(route('login'))->assertSessionHas('status', $message);
            $this->assertGuest();
        }

        $this->assertNull($victim->fresh()->microsoft_id);
        $this->assertTrue(Hash::check('victim-pass', $victim->fresh()->password));
        $this->assertSame(1, User::query()->count());
    }

    public function test_signing_in_takes_over_an_unverified_preregistered_account_and_locks_the_squatter_out(): void
    {
        $squatter = User::factory()->unverified()->create(['email' => 'owner@outlook.com', 'password' => Hash::make('attacker-pass')]);

        $this->signIn(['tid' => MicrosoftLogin::CONSUMER_TENANT, 'email' => 'owner@outlook.com']);
        $this->assertAuthenticatedAs($squatter->fresh());
        Auth::logout();

        $this->assertNotNull($squatter->fresh()->email_verified_at);
        $this->assertFalse(Auth::attempt(['email' => 'owner@outlook.com', 'password' => 'attacker-pass']));
    }

    public function test_suspended_users_are_refused(): void
    {
        $suspended = 'Your account has been suspended. Contact your administrator.';

        $linked = User::factory()->create(['microsoft_id' => self::WORK_TENANT.':'.self::OID]);
        $linked->forceFill(['suspended_at' => now()])->save();
        $this->signIn()->assertRedirect(route('login'))->assertSessionHas('status', $suspended);
        $this->assertGuest();

        $byEmail = User::factory()->create(['email' => 'paused@outlook.com']);
        $byEmail->forceFill(['suspended_at' => now()])->save();
        $this->signIn(['tid' => MicrosoftLogin::CONSUMER_TENANT, 'oid' => '00000000-0000-0000-aaaa-bbbbccccdddd', 'email' => 'paused@outlook.com'])
            ->assertSessionHas('status', $suspended);
        $this->assertGuest();
        $this->assertNull($byEmail->fresh()->microsoft_id);
    }

    public function test_closed_registration_only_lets_invited_addresses_sign_up(): void
    {
        InstanceSettings::instance()->update(['settings' => ['allow_registration' => false]]);
        InstanceSettings::flushCache();

        $this->signIn(['tid' => MicrosoftLogin::CONSUMER_TENANT, 'email' => 'uninvited@outlook.com'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Sign-up is by invitation only. Ask a workspace admin to invite this email address.');
        $this->assertGuest();
        $this->assertSame(0, User::query()->count());

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        WorkspaceInvitation::create(['workspace_id' => $workspace->id, 'email' => 'invited@outlook.com', 'role' => 'editor', 'token' => str()->random(40), 'invited_by' => $owner->id, 'expires_at' => now()->addDays(7)]);

        $this->signIn(['tid' => MicrosoftLogin::CONSUMER_TENANT, 'email' => 'invited@outlook.com'])->assertRedirect(route('dashboard', absolute: false));
        $invited = User::query()->where('email', 'invited@outlook.com')->firstOrFail();
        $this->assertAuthenticatedAs($invited);
        $this->assertSame('editor', $invited->workspaceRole($workspace));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    /**
     * Start the flow, let Microsoft answer with an id_token (claims merged over a valid
     * work-account token for this sign-in; null drops a claim) and come back with the code.
     *
     * @param  array<string, mixed>  $claims
     */
    private function signIn(array $claims = []): TestResponse
    {
        Auth::logout();
        $this->get(route('login.microsoft'));
        $flow = session('auth_microsoft_oauth');

        $tid = $claims['tid'] ?? self::WORK_TENANT;
        $payload = array_filter($claims + [
            'aud' => 'client-123',
            'iss' => 'https://login.microsoftonline.com/'.$tid.'/v2.0',
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 3600,
            'nonce' => $flow['nonce'],
            'tid' => $tid,
            'oid' => self::OID,
            'sub' => 'pairwise-sub',
            'name' => 'Ada Lovelace',
            'preferred_username' => 'ada@corp.example',
            'email' => 'ada@corp.example',
            'ver' => '2.0',
        ], fn ($value) => $value !== null);

        $b64 = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $this->tokenAnswer = [[
            'token_type' => 'Bearer',
            'scope' => 'openid profile email User.Read',
            'expires_in' => 3600,
            'access_token' => 'access-token',
            'id_token' => $b64(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'k1']).'.'.$b64($payload).'.c2lnbmF0dXJl',
        ], 200];

        return $this->get(route('login.microsoft.callback', ['code' => 'auth-code', 'state' => $flow['state']]));
    }
}
