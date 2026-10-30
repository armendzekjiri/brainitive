<?php

namespace App\Providers;

use App\Support\Entitlements\ConfigEntitlements;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\UnlimitedEntitlements;
use Illuminate\Support\ServiceProvider;

/**
 * Cloud plan limits (v1.46.0). Package providers register before the app's, so `singletonIf`
 * leaves brainitive/cloud's binding in place; without the package the configured driver is used.
 */
class EntitlementsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(Entitlements::class, fn () => match (config('entitlements.driver')) {
            'config' => new ConfigEntitlements((array) config('entitlements.config')),
            default => new UnlimitedEntitlements,
        });
    }
}
