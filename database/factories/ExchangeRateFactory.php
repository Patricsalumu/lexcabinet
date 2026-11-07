<?php

namespace Database\Factories;

use App\Enums\RateSource;
use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    public function definition(): array
    {
        return [
            'rate_date' => today(),
            'rate' => '2800.0000',
            'source' => RateSource::Manual,
        ];
    }
}
