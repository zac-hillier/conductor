<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Single-user, localhost-only deployment: there is no real user account
        // to authorise against, so the dashboard is restricted to the local
        // environment and loopback requests rather than gated on an email.
        Horizon::auth(fn ($request) => app()->isLocal()
            || in_array($request->ip(), ['127.0.0.1', '::1'], true));
    }

    /**
     * Register the Horizon gate.
     *
     * Access is handled by the Horizon::auth callback in boot(); this gate is
     * intentionally closed so it never widens dashboard access on its own.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => false);
    }
}
