<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            UserSeeder::class,
            SettingsSeeder::class,
            ExchangeRateSeeder::class,
            ClientAndCaseSeeder::class,
            EmailTemplateSeeder::class,
            AppointmentSeeder::class,
            InvoiceSeeder::class,
        ]);
    }
}
