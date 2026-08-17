<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Throttle before authenticating, not after.
        //
        // Laravel's default priority runs auth first, so a request carrying a
        // junk bearer token is rejected before it ever reaches a rate limiter —
        // leaving every protected endpoint floodable, and paying for a token
        // lookup on each attempt. Below is the default list with the two
        // throttle entries lifted above the auth contract, which has to be
        // restated in full: the relative helpers skip any middleware already on
        // the list, and throttling is already on it.
        //
        // AppServiceProvider::playerKey() resolves the token owner itself for
        // exactly this reason — by the time the throttle runs, nothing has put
        // a user on the request yet.
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        // Every per-address rate limit is only as good as the address we read.
        // Behind a proxy or load balancer the client IP arrives in a forwarded
        // header, and without this the app sees the proxy for every request —
        // one shared bucket that throttles all players together while stopping
        // no one. Set TRUSTED_PROXIES to the proxy addresses in production (or
        // '*' when the platform guarantees the header, e.g. a managed LB).
        //
        // Left unset for local development, where the address is already real.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(
                at: $proxies === '*' ? '*' : explode(',', $proxies),
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
