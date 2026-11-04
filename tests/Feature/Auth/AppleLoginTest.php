<?php

namespace Tests\Feature\Auth;

use App\Models\InstanceSettings;
use App\Models\User;
use App\Support\Auth\AppleLogin;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Sign in with Apple (BRAI-449): authorize → Apple's form_post callback (no session, no CSRF) →
 * the same-site finish step; the ES256 client secret, the id_token checked against a faked JWKS,
 * and the account rules shared with Google / GitHub / Microsoft. Stray requests fail the test.
 */
class AppleLoginTest extends TestCase
{
    use RefreshDatabase;

    private const SUB = '001234.0a1b2c3d4e5f60718293a4b5c6d7e8f9.1234';

    private const KID = 'test-kid-1';

    /** The faked "Apple" RSA signing key (PEM) and the .p8 EC key of the instance. */
    private static string $appleKey;

    private static string $p8;

    /** What the faked token endpoint answers: [json body, status]. */
    private array $tokenAnswer = [[], 200];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        self::$appleKey ??= self::newKey(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::$p8 ??= self::newKey(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        Http::preventStrayRequests();
        Http::fake([
            AppleLogin::TOKEN_URL => fn () => Http::response(...$this->tokenAnswer),
            AppleLogin::KEYS_URL => Http::response(['keys' => [self::jwk(self::$appleKey, self::KID)]]),
        ]);
        InstanceSettings::flushCache();
        config(['services.apple' => [
            'client_id' => 'com.example.brainitive.web',
            'team_id' => 'TEAM123456',
            'key_id' => 'KEY1234567',
            // as an .env line would carry it: one line with \n escapes
            'private_key' => str_replace("\n", '\n', self::$p8),
            'redirect' => 'https://app.test/login/apple/callback',
        ]]);
    }

    protected function tearDown(): void
    {
        InstanceSettings::flushCache();
        parent::tearDown();
    }

    // ── availability, routes, CSRF ──────────────────────────────────────────────────────────

    public function test_login_and_welcome_offer_apple_only_when_it_is_configured(): void
    {
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canLoginWithApple', true));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('canLoginWithApple', true));
        $this->getJson('/api/v1/instance')->assertJsonPath('data.sign_in.apple', true);

        foreach (['client_id', 'team_id', 'key_id', 'private_key'] as $missing) {
            config(['services.apple.'.$missing => null]);
            $this->assertFalse(AppleLogin::available(), $missing);
            config(['services.apple.'.$missing => 'x']);
        }

        config(['services.apple.private_key' => '/no/such/key.p8']);
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canLoginWithApple', false));
        $this->getJson('/api/v1/instance')->assertJsonPath('data.sign_in.apple', false);
        $this->get(route('login.apple'))->assertNotFound();
        $this->post(route('login.apple.callback'), ['code' => 'c', 'state' => 's'])->assertNotFound();
        $this->get(route('login.apple.finish', ['key' => str_repeat('a', 40)]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_private_key_may_be_a_path_to_the_p8_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'apple-p8-');
        file_put_contents($path, self::$p8);
        config(['services.apple.private_key' => $path]);

        try {
            $this->assertTrue(AppleLogin::available());
            $this->assertNotSame('', app(AppleLogin::class)->clientSecret());
        } finally {
            unlink($path);
        }
    }

    public function test_only_the_callback_skips_csrf_and_the_session(): void
    {
        $inExcept = new ReflectionMethod(ValidateCsrfToken::class, 'inExceptArray');
        $csrf = app(ValidateCsrfToken::class);
        $this->assertTrue($inExcept->invoke($csrf, Request::create('/login/apple/callback', 'POST')));
        foreach (['/login/apple', '/login/apple/finish', '/login', '/login/apple/callback/x', '/register'] as $path) {
            $this->assertFalse($inExcept->invoke($csrf, Request::create($path, 'POST')), $path);
        }

        $middleware = fn (string $name) => app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName($name));
        $this->assertNotContains(StartSession::class, $middleware('login.apple.callback'));
        $this->assertNotContains(ValidateCsrfToken::class, $middleware('login.apple.callback'));
        $this->assertContains(StartSession::class, $middleware('login.apple.finish'));
        $this->assertContains(StartSession::class, $middleware('login.apple'));
        $this->assertContains(ValidateCsrfToken::class, $middleware('login'));
    }

    // ── the redirect ────────────────────────────────────────────────────────────────────────

    public function test_the_redirect_asks_for_name_and_email_with_form_post_state_and_nonce(): void
    {
        $location = (string) $this->get(route('login.apple'))->assertRedirect()->headers->get('Location');
        $flow = session('auth_apple_oauth');

        $this->assertStringStartsWith('https://appleid.apple.com/auth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('com.example.brainitive.web', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('form_post', $query['response_mode']);
        $this->assertSame('name email', $query['scope']);
        $this->assertSame('https://app.test/login/apple/callback', $query['redirect_uri']);
        $this->assertSame($flow['state'], $query['state']);
        $this->assertSame($flow['nonce'], $query['nonce']);
        Http::assertNothingSent();
    }

    // ── the callback + finish ───────────────────────────────────────────────────────────────

    public function test_the_callback_sets_no_cookie_and_hands_over_to_a_one_time_get(): void
    {
        $this->get(route('login.apple'));

        $callback = $this->post(route('login.apple.callback'), ['code' => 'c', 'state' => 's'])->assertStatus(303);
        $this->assertSame([], $callback->headers->getCookies());
        $location = (string) $callback->headers->get('Location');
        $this->assertStringStartsWith(route('login.apple.finish').'?key=', $location);

        // the stash is one-time and the answer only counts with this browser's state
        $this->get($location)->assertRedirect(route('login'))->assertSessionHas('status', 'The Apple sign-in expired. Please try again.');
        $this->get($location)->assertSessionHas('status', 'The Apple sign-in expired. Please try again.');
        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_cancelling_at_apple_comes_back_with_a_message(): void
    {
        $this->get(route('login.apple'));
        $state = session('auth_apple_oauth.state');

        $this->followFinish($this->post(route('login.apple.callback'), ['error' => 'user_cancelled_authorize', 'state' => $state]))
            ->assertRedirect(route('login'))->assertSessionHas('status', 'You cancelled signing in with Apple.');
        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_new_user_signs_up_with_the_name_from_the_first_callback(): void
    {
        $this->signIn(['email' => 'New.Person@Example.com'], user: ['name' => ['firstName' => 'New', 'lastName' => 'Person'], 'email' => 'ignored@example.com'])
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'new.person@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(self::SUB, $user->apple_id);
        $this->assertSame('New Person', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->terms_accepted_at);

        // the token request carries a valid ES256 client secret signed with the .p8 key
        Http::assertSent(function (HttpRequest $request) {
            if ($request->url() !== AppleLogin::TOKEN_URL) {
                return false;
            }
            $public = openssl_pkey_get_details(openssl_pkey_get_private(self::$p8))['key'];
            [$header] = explode('.', $request['client_secret']);
            $claims = (array) JWT::decode($request['client_secret'], new Key($public, 'ES256'));

            return $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'auth-code'
                && $request['client_id'] === 'com.example.brainitive.web'
                && $request['redirect_uri'] === 'https://app.test/login/apple/callback'
                && json_decode(JWT::urlsafeB64Decode($header), true)['kid'] === 'KEY1234567'
                && $claims['iss'] === 'TEAM123456' && $claims['sub'] === 'com.example.brainitive.web'
                && $claims['aud'] === 'https://appleid.apple.com' && $claims['exp'] <= time() + 300;
        });
    }

    public function test_a_private_relay_email_is_a_verified_email(): void
    {
        $relay = 'x7k2m9q4ab@privaterelay.appleid.com';

        $this->signIn(['email' => $relay, 'email_verified' => 'true', 'is_private_email' => 'true'])
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', $relay)->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Apple user', $user->name); // no name shared, and the relay local part is random
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_an_existing_account_is_linked_by_its_verified_email(): void
    {
        $user = User::factory()->create(['email' => 'Someone@Example.com', 'name' => 'Some One']);

        $this->signIn(['email' => 'someone@example.com'], user: ['name' => ['firstName' => 'Other', 'lastName' => 'Name']])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(self::SUB, $user->fresh()->apple_id);
        $this->assertSame('Some One', $user->fresh()->name);
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_never_verified_squatter_account_is_reset_before_linking(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'victim@example.com', 'password' => bcrypt('squatter-pass')]);

        $this->signIn(['email' => 'victim@example.com'])->assertRedirect(route('dashboard', absolute: false));

        $user->refresh();
        $this->assertSame(self::SUB, $user->apple_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse(password_verify('squatter-pass', $user->password));
    }

    public function test_an_unverified_email_neither_links_nor_signs_up_but_a_linked_account_signs_in(): void
    {
        $user = User::factory()->create(['email' => 'someone@example.com']);

        $this->signIn(['email' => 'someone@example.com', 'email_verified' => false])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'We couldn’t confirm the email address of this Apple ID.'));
        $this->assertGuest();
        $this->assertNull($user->fresh()->apple_id);

        $user->update(['apple_id' => self::SUB]);
        $this->signIn(['email_verified' => false, 'email' => null])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_sign_up_respects_invite_only_instances(): void
    {
        InstanceSettings::instance()->update(['settings' => ['allow_registration' => false]]);
        InstanceSettings::flushCache();

        $this->signIn(['email' => 'stranger@example.com'])
            ->assertSessionHas('status', 'Sign-up is by invitation only. Ask a workspace admin to invite this email address.');
        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_suspended_user_is_refused(): void
    {
        User::factory()->create(['apple_id' => self::SUB, 'suspended_at' => now()]);

        $this->signIn()->assertSessionHas('status', 'Your account has been suspended. Contact your administrator.');
        $this->assertGuest();
    }

    public function test_id_tokens_not_signed_by_apple_or_for_another_app_or_sign_in_are_refused(): void
    {
        $user = User::factory()->create(['apple_id' => self::SUB]);
        $refused = 'Something went wrong signing in with Apple. Please try again.';

        $otherKey = self::newKey(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        foreach ([
            'another signing key' => [[], $otherKey, self::KID],
            'an unknown kid' => [[], self::$appleKey, 'unknown-kid'],
            'another app' => [['aud' => 'com.example.other'], null, null],
            'another issuer' => [['iss' => 'https://evil.example'], null, null],
            'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200], null, null],
            'another nonce' => [['nonce' => 'not-this-sign-in'], null, null],
            'no subject' => [['sub' => ''], null, null],
        ] as $case => [$claims, $key, $kid]) {
            $this->signIn($claims, key: $key, kid: $kid)->assertRedirect(route('login'))->assertSessionHas('status', $refused);
            $this->assertGuest();
        }

        $this->signIn()->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user); // the same token with the right claims is accepted
    }

    public function test_a_refused_code_exchange_signs_no_one_in(): void
    {
        $this->get(route('login.apple'));
        $state = session('auth_apple_oauth.state');
        $this->tokenAnswer = [['error' => 'invalid_grant'], 400];

        $this->followFinish($this->post(route('login.apple.callback'), ['code' => 'c', 'state' => $state]))
            ->assertRedirect(route('login'))->assertSessionHas('status', 'Apple didn’t accept the sign-in. Please try again.');
        $this->assertGuest();
    }

    public function test_the_name_field_is_read_defensively(): void
    {
        $this->assertSame('Ada Lovelace', AppleLogin::nameFromUserField('{"name":{"firstName":" Ada ","lastName":"Lovelace"}}'));
        $this->assertSame('Ada', AppleLogin::nameFromUserField('{"name":{"firstName":"Ada"}}'));
        $this->assertNull(AppleLogin::nameFromUserField('{"name":{"firstName":"","lastName":"  "}}'));
        $this->assertNull(AppleLogin::nameFromUserField('{"email":"a@b.c"}'));
        $this->assertNull(AppleLogin::nameFromUserField('not json'));
        $this->assertNull(AppleLogin::nameFromUserField(['name' => 'array']));
        $this->assertNull(AppleLogin::nameFromUserField(null));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    /**
     * The whole browser round trip: our redirect, Apple's form_post with an id_token carrying
     * `$claims` (null removes a claim), then the finish step.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>|null  $user  the `user` form field Apple sends the first time
     */
    private function signIn(array $claims = [], ?array $user = null, ?string $key = null, ?string $kid = null): TestResponse
    {
        Cache::forget('auth:apple:jwks');
        $this->get(route('login.apple'))->assertRedirect();
        $flow = session('auth_apple_oauth');

        $payload = array_filter(array_merge([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'com.example.brainitive.web',
            'exp' => time() + 600,
            'iat' => time(),
            'sub' => self::SUB,
            'nonce' => $flow['nonce'],
            'email' => 'person@example.com',
            'email_verified' => true,
        ], $claims), fn ($v) => $v !== null);
        $this->tokenAnswer = [['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => JWT::encode($payload, $key ?? self::$appleKey, 'RS256', $kid ?? self::KID)], 200];

        $form = ['code' => 'auth-code', 'state' => $flow['state']];
        if ($user !== null) {
            $form['user'] = json_encode($user);
        }

        return $this->followFinish($this->post(route('login.apple.callback'), $form));
    }

    private function followFinish(TestResponse $callback): TestResponse
    {
        $callback->assertStatus(303);

        return $this->get((string) $callback->headers->get('Location'));
    }

    /** @param  array<string, mixed>  $options */
    private static function newKey(array $options): string
    {
        openssl_pkey_export(openssl_pkey_new($options), $pem);

        return $pem;
    }

    /** @return array<string, string> the public JWK of an RSA key, as Apple publishes it */
    private static function jwk(string $pem, string $kid): array
    {
        $rsa = openssl_pkey_get_details(openssl_pkey_get_private($pem))['rsa'];

        return ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256',
            'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e'])];
    }
}
