<?php

namespace App\Providers;

use App\Models\Profile;
use App\Observers\ProfileObserver;
use App\Services\Claude\ClaudeRunner;
use App\Services\Claude\ProcessClaudeRunner;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ClaudeRunner::class, ProcessClaudeRunner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Profile::observe(ProfileObserver::class);

        // Served behind an nginx reverse proxy (conductor.zh) that rewrites the
        // Host header to the backend address. Force generated URLs (assets,
        // Livewire endpoint, notification links) to the canonical app URL so
        // they stay same-origin as the page and avoid cross-origin (CORS) blocks.
        if ($root = config('app.url')) {
            URL::forceRootUrl($root);
        }
    }
}
