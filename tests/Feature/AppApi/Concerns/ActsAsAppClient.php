<?php

namespace Tests\Feature\AppApi\Concerns;

use App\Models\AppDevice;
use App\Models\User;
use App\Support\AppApi\AppTokens;
use Laravel\Passport\ClientRepository;
use Tests\Feature\Concerns\UsesPassportKeys;

/**
 * App API v1 tests: real app tokens for a user, and real Passport tokens (MCP / CLI / extension)
 * to prove they are rejected on /api/v1 — the "Passport token → 401" line of the definition of done.
 */
trait ActsAsAppClient
{
    use UsesPassportKeys;

    /** A signed-in desktop device for the user. */
    protected function appDevice(User $user, array $attributes = []): AppDevice
    {
        return AppDevice::create([
            'user_id' => $user->id,
            'client' => 'desktop',
            'platform' => 'macos',
            'name' => 'Test Mac',
            'app_version' => '0.1.0',
            ...$attributes,
        ]);
    }

    /** A valid access token (and its device) for the user. */
    protected function appToken(User $user, ?AppDevice $device = null): string
    {
        return AppTokens::issue($device ?? $this->appDevice($user))['access_token'];
    }

    /** @return array<string, string> */
    protected function appHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /** A real Passport personal access token, as MCP / CLI / extension clients hold. */
    protected function passportToken(User $user, array $scopes = ['mcp:use']): string
    {
        $this->usePassportKeys();
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test personal access', 'users');

        return $user->createToken('test', $scopes)->accessToken;
    }
}
