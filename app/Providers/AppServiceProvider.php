<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
    }
}
