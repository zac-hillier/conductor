<?php

namespace App\Providers;

use App\Services\Claude\ClaudeRunner;
use App\Services\Claude\ProcessClaudeRunner;
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
        //
    }
}
