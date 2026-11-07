<?php

namespace Database\Seeders;

use App\Enums\RateSource;
use App\Models\User;
use App\Services\ExchangeRateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Taux de démonstration du prototype : 2 790 FC il y a 7 jours, 2 800 FC aujourd'hui.
 */
class ExchangeRateSeeder extends Seeder
{
    public function run(ExchangeRateService $rates): void
    {
        $admin = User::query()->where('email', 'grace.mukendi@mukendi-avocats.example')->first();

        $rates->record(Carbon::today()->subDays(7), '2790', RateSource::Manual, $admin);
        $rates->record(Carbon::today(), '2800', RateSource::Manual, $admin);
    }
}
