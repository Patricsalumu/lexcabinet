<?php

use App\Enums\Permission;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FirmLogoController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\ReceiptPdfController;
use App\Livewire\Admin\TreasuryAccounts;
use App\Livewire\Cash\CashDesk;
use App\Livewire\Cash\DisbursementForm;
use App\Livewire\Cash\ReceiptForm;
use App\Livewire\Invoices\InvoiceCreate;
use App\Livewire\Invoices\InvoiceIndex;
use App\Livewire\Invoices\InvoiceShow;
use App\Livewire\Agenda\AgendaIndex;
use App\Livewire\Agenda\AppointmentForm;
use App\Livewire\Cases\CaseForm;
use App\Livewire\Cases\CaseIndex;
use App\Livewire\Cases\CaseShow;
use App\Livewire\Clients\ClientForm;
use App\Livewire\Clients\ClientIndex;
use App\Livewire\Clients\ClientShow;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Admin\ExchangeRates;
use App\Livewire\Admin\FirmSettings;
use App\Livewire\Admin\UserManager;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Auth\TwoFactorSetup;
use App\Livewire\Dashboard;
use App\Livewire\Emails\EmailLogIndex;
use App\Livewire\Emails\EmailTemplates;
use App\Livewire\Messages\ConversationIndex;
use App\Http\Controllers\ReplyAttachmentController;
use App\Http\Controllers\AccountActivationController;
use App\Http\Controllers\AccountingExportController;
use App\Livewire\Accounting\AccountingIndex;
use App\Livewire\Accounting\ChartOfAccounts;
use App\Livewire\Accounting\ManualEntry;
use App\Livewire\Platform\FirmCreate;
use App\Livewire\Platform\FirmIndex;
use App\Livewire\Platform\FirmShow;
use App\Livewire\Platform\PlatformAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tableau-de-bord');

Route::get('connexion/verification', TwoFactorChallenge::class)
    ->middleware('guest')
    ->name('two-factor.challenge');

// Lien d'accès envoyé à la création d'un compte (signature vérifiée dans le contrôleur).
Route::get('compte/acces/{user}', AccountActivationController::class)
    ->whereNumber('user')
    ->middleware('throttle:10,1')
    ->name('account.activate');

// Compte connecté, actif et non verrouillé.
Route::middleware(['auth', 'account.usable'])->group(function () {
    Route::get('securite/double-authentification', TwoFactorSetup::class)->name('two-factor.setup');
    Route::get('parametres/logo', FirmLogoController::class)->middleware('firm.member')->name('firm.logo');

    // Double authentification obligatoire pour les administrateurs.
    Route::middleware('two-factor')->group(function () {
        Route::view('profil', 'profile')->name('profile');

        // Espace Plateforme (super administrateur) : cabinets et leurs administrateurs, jamais leurs données.
        Route::prefix('plateforme')->name('platform.')->middleware('can:platform.manage')->group(function () {
            Route::get('/', FirmIndex::class)->name('firms');
            Route::get('cabinets/nouveau', FirmCreate::class)->name('firms.create');
            Route::get('cabinets/{firm}', FirmShow::class)->whereNumber('firm')->name('firms.show');
            Route::get('journal', PlatformAuditLog::class)->name('audit');
        });

    // Pages des cabinets : réservées aux membres d'un cabinet (le super administrateur en est exclu).
    Route::middleware('firm.member')->group(function () {
        Route::get('tableau-de-bord', Dashboard::class)
            ->middleware('can:dashboard.view')
            ->name('dashboard');

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
            Route::get('tresorerie', TreasuryAccounts::class)->middleware('can:settings.manage')->name('treasury');
        });

        // Clients (CLI-01 à CLI-04)
        Route::prefix('clients')->name('clients.')->group(function () {
            Route::get('/', ClientIndex::class)->name('index');
            Route::get('nouveau', ClientForm::class)->name('create');
            Route::get('{client}', ClientShow::class)->whereNumber('client')->name('show');
            Route::get('{client}/modifier', ClientForm::class)->whereNumber('client')->name('edit');
        });

        // Dossiers (DOS-01 à DOS-08) ; chaque composant vérifie la policy du dossier.
        Route::prefix('dossiers')->name('cases.')->group(function () {
            Route::get('/', CaseIndex::class)->name('index');
            Route::get('nouveau', CaseForm::class)->name('create');
            Route::get('{case}', CaseShow::class)->whereNumber('case')->name('show');
            Route::get('{case}/modifier', CaseForm::class)->whereNumber('case')->name('edit');
            Route::get('{case}/pieces/{document}', DocumentController::class)
                ->whereNumber(['case', 'document'])
                ->name('documents.download');
        });

        // Agenda (RDV-01 à RDV-06)
        Route::prefix('agenda')->name('agenda.')->group(function () {
            Route::get('/', AgendaIndex::class)->name('index');
            Route::get('nouveau', AppointmentForm::class)->name('create');
            Route::get('{appointment}/modifier', AppointmentForm::class)->whereNumber('appointment')->name('edit');
        });

        // E-mails (NOT-06, NOT-07)
        // Conversations : réponses reçues aux e-mails de l'application (droits vérifiés par le composant).
        Route::get('messages', ConversationIndex::class)->name('messages.index');
        Route::get('messages/pieces/{reply}/{index}', ReplyAttachmentController::class)
            ->whereNumber(['reply', 'index'])->name('messages.attachment');

        Route::prefix('emails')->name('emails.')->group(function () {
            Route::get('/', EmailLogIndex::class)->middleware('can:emails.view')->name('index');
            Route::get('modeles', EmailTemplates::class)->middleware('can:email_templates.manage')->name('templates');
        });

        // Factures (FAC-01 à FAC-14) ; la création est refusée côté serveur sans invoices.manage.
        Route::prefix('factures')->name('invoices.')->group(function () {
            Route::get('/', InvoiceIndex::class)->name('index');
            Route::get('nouvelle', InvoiceCreate::class)->middleware('can:invoices.manage')->name('create');
            Route::get('{invoice}', InvoiceShow::class)->whereNumber('invoice')->name('show');
            Route::get('{invoice}/pdf', [InvoicePdfController::class, 'invoice'])->whereNumber('invoice')->name('pdf');
        });
        Route::get('avoirs/{creditNote}/pdf', [InvoicePdfController::class, 'creditNote'])->whereNumber('creditNote')->name('credit-notes.pdf');

        // Caisse (CAI-01 à CAI-08)
        Route::prefix('caisse')->name('cash.')->group(function () {
            Route::get('/', CashDesk::class)->middleware('can:cash.view')->name('index');
            Route::get('encaisser', ReceiptForm::class)->middleware('can:cash.operate')->name('receipt');
            Route::get('decaisser', DisbursementForm::class)->middleware('can:cash.operate')->name('disbursement');
            Route::get('recus/{movement}', ReceiptPdfController::class)->whereNumber('movement')->name('receipt-pdf');
        });

        // Comptabilité (CPT-01 à CPT-07)
        Route::prefix('comptabilite')->name('accounting.')->middleware('can:accounting.view')->group(function () {
            Route::get('/', AccountingIndex::class)->name('index');
            Route::get('plan-comptable', ChartOfAccounts::class)->name('chart');
            Route::get('ecriture', ManualEntry::class)->middleware('can:accounting.manage')->name('manual');
            Route::get('export/{report}.{format}', AccountingExportController::class)
                ->whereIn('report', ['journal', 'ledger', 'balance', 'result'])
                ->whereIn('format', ['xlsx', 'pdf'])
                ->name('export');
        });
    });
    });
});

require __DIR__.'/auth.php';
