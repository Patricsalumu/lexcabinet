<?php

namespace Database\Factories;

use App\Enums\CaseStatus;
use App\Enums\Currency;
use App\Enums\FeeType;
use App\Enums\Matter;
use App\Models\Client;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalCase>
 */
class LegalCaseFactory extends Factory
{
    protected $model = LegalCase::class;

    public function definition(): array
    {
        return [
            'reference' => 'LEX-'.now()->year.'-'.fake()->unique()->numerify('9###'),
            'title' => fake()->words(4, true),
            'client_id' => Client::factory(),
            'adverse_party' => null,
            'lead_lawyer_id' => User::factory(),
            'matter' => Matter::Civil,
            'status' => CaseStatus::InProgress,
            'fee_type' => FeeType::Flat,
            'fee_amount' => '2500.00',
            'currency' => Currency::USD,
            'opened_on' => today()->subDays(10),
        ];
    }
}
