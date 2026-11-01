<?php

use App\Providers\AppApiDocsServiceProvider;
use App\Providers\AppApiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\EntitlementsServiceProvider;
use App\Providers\LiveServiceProvider;
use App\Providers\SearchServiceProvider;

return [
    AppServiceProvider::class,
    AppApiServiceProvider::class,
    AppApiDocsServiceProvider::class,
    BillingServiceProvider::class,
    EntitlementsServiceProvider::class,
    LiveServiceProvider::class,
    SearchServiceProvider::class,
];
