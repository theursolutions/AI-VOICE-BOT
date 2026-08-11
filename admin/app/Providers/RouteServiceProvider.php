<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            // Deliberately NO middleware group: /robots.txt and /sitemap.xml
            // need no session, cookies or CSRF, and a Set-Cookie header on
            // them makes the responses uncacheable by any shared cache.
            Route::group([], base_path('routes/crawler.php'));

            // Stripe webhook — same treatment, same reasons: no session, no
            // CSRF token to present, nothing to bind. Authentication is the
            // Stripe signature, verified inside the controller. Inside the
            // `web` group every delivery would 419 and Stripe would retry
            // it for days.
            Route::group([], base_path('routes/stripe.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
