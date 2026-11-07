<?php

namespace Tests\Feature\ApiKeys\Concerns;

use App\Models\ApiKey;
use App\Models\User;
use App\Support\ApiKeys\ApiKeyAreas;
use App\Support\ApiKeys\ApiKeys;
use Illuminate\Support\Facades\Notification;

/** Developer API tests: real personal API keys (the stored hash, the plain key for the header). */
trait UsesApiKeys
{
    /**
     * @param  array<string, string>  $abilities  area => none | read | write (missing: none)
     * @param  list<int>  $scopeIds
     * @return array{key: ApiKey, secret: string}
     */
    protected function makeApiKey(User $user, array $abilities = ['issues' => 'write'], string $scope = ApiKey::SCOPE_ALL, array $scopeIds = [], bool $canDelete = false, ?int $days = null): array
    {
        Notification::fake();

        return ApiKeys::create($user, 'Test key', $scope, $scopeIds, $abilities, $canDelete, $days, '203.0.113.9');
    }

    /** @param  array<string, string>  $abilities */
    protected function apiKey(User $user, array $abilities = ['issues' => 'write'], string $scope = ApiKey::SCOPE_ALL, array $scopeIds = [], bool $canDelete = false): string
    {
        return $this->makeApiKey($user, $abilities, $scope, $scopeIds, $canDelete)['secret'];
    }

    /** Read + write on every area. */
    protected function everything(): array
    {
        return array_fill_keys(ApiKeyAreas::keys(), ApiKeyAreas::WRITE);
    }

    /** @return array<string, string> */
    protected function keyHeaders(string $secret): array
    {
        return ['Authorization' => 'Bearer '.$secret];
    }
}
