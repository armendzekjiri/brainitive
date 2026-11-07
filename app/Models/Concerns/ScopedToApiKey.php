<?php

namespace App\Models\Concerns;

use App\Support\ApiKeys\ApiKeyAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Developer API (v1.52.0): while a personal API key's request runs, every query of this model only sees rows in the
 * key's scope (ApiKeyAccess::scopeModel — the one place that knows which models are project / board level and
 * which are workspace level). Outside a key's request it does nothing.
 */
trait ScopedToApiKey
{
    public static function bootScopedToApiKey(): void
    {
        static::addGlobalScope('api-key', function (Builder $query): void {
            ApiKeyAccess::current()?->scopeModel($query);
        });
    }
}
