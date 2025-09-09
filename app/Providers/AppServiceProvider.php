<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Gate as FacadesGate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ActivityLogService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FacadesGate::policy(User::class, UserPolicy::class);
    }
}
