<?php

use App\Enums\Module;
use App\Enums\Permission;
use App\Http\Controllers\FirmLogoController;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Admin\ExchangeRates;
use App\Livewire\Admin\FirmSettings;
use App\Livewire\Admin\UserManager;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Auth\TwoFactorSetup;
use App\Livewire\Dashboard;
use App\Livewire\ModulePlaceholder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tableau-de-bord');

Route::get('connexion/verification', TwoFactorChallenge::class)
    ->middleware('guest')
    ->name('two-factor.challenge');

// Compte connecté, actif et non verrouillé.
Route::middleware(['auth', 'account.usable'])->group(function () {
    Route::get('securite/double-authentification', TwoFactorSetup::class)->name('two-factor.setup');
    Route::get('parametres/logo', FirmLogoController::class)->name('firm.logo');

    // Double authentification obligatoire pour les administrateurs.
    Route::middleware('two-factor')->group(function () {
        Route::get('tableau-de-bord', Dashboard::class)
            ->middleware('can:dashboard.view')
            ->name('dashboard');

        Route::view('profil', 'profile')->name('profile');

        Route::prefix('parametres')->name('settings.')->group(function () {
            Route::get('/', function (Request $request) {
                foreach ([
                    'settings.firm' => Permission::SettingsManage,
                    'settings.users' => Permission::UsersManage,
                    'settings.rates' => Permission::RatesManage,
                    'settings.audit' => Permission::AuditView,
                ] as $route => $permission) {
                    if ($request->user()->can($permission->value)) {
                        return redirect()->route($route);
                    }
                }
                abort(403);
            })->name('index');

            Route::get('cabinet', FirmSettings::class)->middleware('can:settings.manage')->name('firm');
            Route::get('utilisateurs', UserManager::class)->middleware('can:users.manage')->name('users');
            Route::get('taux-de-change', ExchangeRates::class)->middleware('can:rates.manage')->name('rates');
            Route::get('journal-audit', AuditLog::class)->middleware('can:audit.view')->name('audit');
        });

        // Modules des phases suivantes : accès déjà contrôlé côté serveur.
        $future = collect(Module::cases())
            ->reject(fn (Module $m) => in_array($m, [Module::Dashboard, Module::Settings], true))
            ->map->value
            ->all();

        Route::get('{module}', ModulePlaceholder::class)
            ->whereIn('module', $future)
            ->middleware('can:view-module,module')
            ->name('module');
    });
});

require __DIR__.'/auth.php';
