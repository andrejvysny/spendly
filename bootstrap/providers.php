<?php

use Illuminate\Support\ServiceProvider;

return ServiceProvider::defaultProviders()->merge([
    /*
     * Package Service Providers...
     */

    /*
     * Application Service Providers...
     */
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\EventServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
    App\Providers\GoCardlessServiceProvider::class,
    App\Providers\RuleEngineServiceProvider::class,
])->toArray();
