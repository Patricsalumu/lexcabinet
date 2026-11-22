<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\TreasuryType;
use App\Models\Account;
use App\Models\EmailTemplate;
use App\Models\Firm;
use App\Models\TreasuryAccount;
use App\Support\EmailTemplateDefaults;
use App\Support\Tenancy\CurrentFirm;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Création d'un cabinet prêt à l'emploi : paramètres d'identité, plan comptable SYSCOHADA,
 * modèles d'e-mails et caisses USD / CDF. Idempotent : ne remplace rien d'existant.
 */
class FirmProvisioner
{
    public function __construct(private readonly CurrentFirm $current, private readonly SettingsService $settings) {}

    /** @param  array<string, mixed>  $settings  paramètres initiaux (firm.email, firm.phone…) */
    public function create(string $name, array $settings = []): Firm
    {
        return DB::transaction(function () use ($name, $settings) {
            $firm = Firm::query()->create(['name' => $name, 'is_active' => true]);
            $this->provision($firm, ['firm.name' => $name] + $settings);

            return $firm;
        });
    }

    /** @param  array<string, mixed>  $settings */
    public function provision(Firm $firm, array $settings = []): void
    {
        $this->current->run($firm, function () use ($settings) {
            foreach (ChartOfAccountsSeeder::ACCOUNTS as $code => $label) {
                Account::query()->firstOrCreate(['code' => (string) $code], ['label' => $label, 'class' => (int) substr((string) $code, 0, 1), 'is_active' => true]);
            }

            foreach (EmailTemplateDefaults::all() as $key => $template) {
                EmailTemplate::query()->firstOrCreate(['key' => $key], $template);
            }

            // Caisse physique en deux devises (CAI-01), indispensable à l'ouverture de session.
            TreasuryAccount::query()->firstOrCreate(
                ['type' => TreasuryType::Cash, 'currency' => Currency::USD, 'name' => 'Caisse USD'],
                ['ledger_code' => '5711', 'is_active' => true],
            );
            TreasuryAccount::query()->firstOrCreate(
                ['type' => TreasuryType::Cash, 'currency' => Currency::CDF, 'name' => 'Caisse CDF'],
                ['ledger_code' => '5712', 'is_active' => true],
            );

            if ($settings !== []) {
                $this->settings->setMany($settings);
            }
        });
    }
}
