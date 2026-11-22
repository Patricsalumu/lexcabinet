<?php

namespace App\Providers;

use App\Enums\Module;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Tenancy\CurrentFirm;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(CurrentFirm::class);
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));

        $this->bootFirmContextForQueues();

        // Espace Plateforme : réservé au super administrateur (aucun cabinet).
        Gate::define('platform.manage', fn (User $user): bool => $user->isSuperAdmin());

        // Accès à un module de la navigation : au moins une des permissions du module.
        Gate::define('view-module', function (User $user, Module|string $module): bool {
            $module = $module instanceof Module ? $module : Module::tryFrom($module);

            if ($module === null) {
                return false;
            }

            return $user->hasAnyPermission(array_map(fn ($p) => $p->value, $module->permissions()));
        });
    }

    /**
     * Multi-cabinet : chaque job (e-mails…) emporte le cabinet courant dans sa charge utile,
     * et le worker se place dans ce cabinet le temps de le traiter, puis rétablit le contexte.
     */
    private function bootFirmContextForQueues(): void
    {
        Queue::createPayloadUsing(fn () => ['firm_id' => app(CurrentFirm::class)->id()]);

        // Contexte précédent, par job : rétabli une seule fois, quel que soit l'événement de fin.
        $previous = [];
        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$previous) {
            $previous[$event->job->getJobId() ?? spl_object_id($event->job)] = app(CurrentFirm::class)->id();
            app(CurrentFirm::class)->set($event->job->payload()['firm_id'] ?? null);
        });

        $restore = function (JobProcessed|JobFailed|JobExceptionOccurred $event) use (&$previous) {
            $key = $event->job->getJobId() ?? spl_object_id($event->job);
            if (array_key_exists($key, $previous)) {
                app(CurrentFirm::class)->set($previous[$key]);
                unset($previous[$key]);
            }
        };
        Event::listen(JobProcessed::class, $restore);
        Event::listen(JobFailed::class, $restore);
        Event::listen(JobExceptionOccurred::class, $restore);
    }
}
