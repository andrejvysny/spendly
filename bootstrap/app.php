<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SuperAdminMiddleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('gocardless:dispatch-sync')->everyFourHours()->withoutOverlapping();
        $schedule->command('gocardless:retry-failures')->everyThirtyMinutes()->withoutOverlapping();
        $schedule->command('gocardless:check-consent')->dailyAt('05:30')->withoutOverlapping();
        $schedule->command('recurring:detect')->daily()->withoutOverlapping();
        $schedule->command('exchange-rates:fetch')->dailyAt('06:00')->withoutOverlapping();
        $schedule->command('queue:prune-failed --hours=72')->daily();
        $schedule->command('queue:prune-batches --hours=72')->daily();
    })
    ->withMiddleware(function (Middleware $middleware) {
        // Trust proxies behind a TLS-terminating reverse proxy so Laravel generates https:// URLs.
        // This closure runs on every boot (never config-cached), so env() here is safe.
        // @phpstan-ignore-next-line — bootstrap closure, no config/ equivalent
        $trustedProxies = env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(at: is_string($trustedProxies) ? $trustedProxies : '*');

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'superadmin' => SuperAdminMiddleware::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
