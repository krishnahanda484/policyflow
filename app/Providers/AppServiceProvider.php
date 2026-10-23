<?php

namespace App\Providers;

use App\Insurer\InsurerGateway;
use App\Insurer\MockInsurer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Point this at a real provider adapter to leave the sandbox; nothing else changes.
        $this->app->bind(InsurerGateway::class, MockInsurer::class);
    }

    public function boot(): void
    {
        //
    }
}
