<?php

namespace App\Providers;

use App\Apps\WelcomeApp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use SystemX\Core\Runtime\AppRegistry;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Login throttle (D8): 5 attempts per minute keyed on email+IP. The known
        // seeded demo credential (D2) makes an unthrottled login an open brute-force
        // target; this is the standard Laravel mitigation, applied as throttle:login
        // middleware on POST /login. A 6th attempt in the window -> 429.
        RateLimiter::for('login', function (Request $request): Limit {
            $key = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Live-demo mint throttle (showcase plan): 5 provisions/min per IP. Stops a trivial
        // mint-in-a-loop; generous for real humans behind shared NAT. Distributed mint is an
        // accepted residual (the cap + prune bound the blast radius).
        RateLimiter::for('demo', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Live-demo welcome app (showcase plan). Registered ONLY when demo mode is on, so a
        // non-demo host keeps a byte-identical launcher. Registered in boot() (not register())
        // because the AppRegistry singleton is bound by the framework provider and only exists
        // once every provider's register() has run.
        if (config('system-x-demo.enabled')) {
            $this->app->make(AppRegistry::class)
                ->register('welcome', WelcomeApp::class);
        }
    }
}
