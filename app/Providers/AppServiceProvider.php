<?php

namespace App\Providers;

use App\Enums\Module;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));

        // Accès à un module de la navigation : au moins une des permissions du module.
        Gate::define('view-module', function (User $user, Module|string $module): bool {
            $module = $module instanceof Module ? $module : Module::tryFrom($module);

            if ($module === null) {
                return false;
            }

            return $user->hasAnyPermission(array_map(fn ($p) => $p->value, $module->permissions()));
        });
    }
}
