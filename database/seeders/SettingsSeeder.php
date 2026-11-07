<?php

namespace Database\Seeders;

use App\Services\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Paramètres de démonstration repris du prototype (Cabinet Mukendi & Associés).
 */
class SettingsSeeder extends Seeder
{
    public function run(SettingsService $settings): void
    {
        $settings->setMany([
            'firm.name' => 'Cabinet Mukendi & Associés',
            'firm.bar' => 'Barreau de Kinshasa/Gombe',
            'firm.address' => '12, avenue de la Justice, Gombe, Kinshasa',
            'firm.rccm' => 'CD/KNG/RCCM/19-B-02231',
            'firm.id_nat' => '01-83-N48211K',
            'firm.nif' => 'A1905521Q',
            'firm.phone' => '+243 81 000 0000',
            'firm.email' => 'contact@mukendi-avocats.example',
            'firm.bank_details' => 'Rawbank, compte USD 0501-0000000-01',
            'vat.enabled' => false,
            'vat.rate' => '16.00',
            'cash.disbursement_threshold_usd' => '500.00',
            'consultation.default_fee' => '100.00',
            'consultation.default_currency' => 'USD',
            'subscription_auto_issue' => false,
            'reminders.day_before_time' => '18:00',
            'reminders.hours_before' => 2,
            'recap.time' => '07:00',
            'office.opens_at' => '08:00',
            'office.closes_at' => '17:00',
            'office.days' => [1, 2, 3, 4, 5],
        ]);
    }
}
