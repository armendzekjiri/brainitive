<?php

use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\EntitlementsServiceProvider;
use App\Providers\LiveServiceProvider;
use App\Providers\SearchServiceProvider;

return [
    AppServiceProvider::class,
    BillingServiceProvider::class,
    EntitlementsServiceProvider::class,
    LiveServiceProvider::class,
    SearchServiceProvider::class,
];
