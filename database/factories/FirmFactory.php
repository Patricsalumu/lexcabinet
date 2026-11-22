<?php

namespace Database\Factories;

use App\Models\Firm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Firm> */
class FirmFactory extends Factory
{
    protected $model = Firm::class;

    public function definition(): array
    {
        return [
            'name' => 'Cabinet '.fake()->unique()->lastName(),
            'is_active' => true,
        ];
    }
}
