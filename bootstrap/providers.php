<?php

use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\LiveServiceProvider;
use App\Providers\SearchServiceProvider;

return [
    AppServiceProvider::class,
    BillingServiceProvider::class,
    LiveServiceProvider::class,
    SearchServiceProvider::class,
];
