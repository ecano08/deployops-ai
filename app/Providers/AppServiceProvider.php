<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|'.$request->string('email')->lower());
        });

        RateLimiter::for('integration-webhooks', function (Request $request) {
            $integration = $request->route('deployment_integration');
            $integrationKey = is_object($integration) ? $integration->getKey() : $integration;

            return [
                Limit::perMinute(60)->by($request->ip()),
                Limit::perMinute(30)->by($request->ip().'|integration|'.$integrationKey),
            ];
        });

        RateLimiter::for('copilot', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute(10)->by($user?->getKey() ?? $request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute(120)->by($user?->getKey() ?? $request->ip());
        });
    }
}
