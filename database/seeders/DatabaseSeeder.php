<?php

namespace Database\Seeders;

use App\Models\Firm;
use App\Support\Tenancy\CurrentFirm;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Cabinet n° 1 : données de démonstration du prototype (Cabinet Mukendi & Associés).
        $firm = Firm::query()->firstOrCreate(['name' => 'Cabinet Mukendi & Associés'], ['is_active' => true]);

        app(CurrentFirm::class)->run($firm, fn () => $this->call([
            ChartOfAccountsSeeder::class,
            UserSeeder::class,
            SettingsSeeder::class,
            ExchangeRateSeeder::class,
            ClientAndCaseSeeder::class,
            EmailTemplateSeeder::class,
            AppointmentSeeder::class,
            InvoiceSeeder::class,
            CashSeeder::class,
            AccountingSeeder::class,
        ]));

        // Cabinet n° 2 : pour vérifier dans le navigateur que les cabinets ne se voient pas.
        $this->call(SecondFirmDemoSeeder::class);
    }
}
