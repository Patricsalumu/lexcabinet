<?php

namespace Database\Factories;

use App\Enums\ClientType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'type' => ClientType::Individual,
            'name' => fake()->unique()->name(),
            'phone' => '+243 81 '.fake()->numerify('### ####'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->streetAddress().', Kinshasa',
            'email_consent' => true,
            'is_prospect' => false,
        ];
    }

    public function company(): static
    {
        return $this->state(fn () => [
            'type' => ClientType::Company,
            'name' => fake()->unique()->company().' SARL',
            'rccm' => 'CD/KNG/RCCM/'.fake()->numerify('##-B-#####'),
            'id_nat' => fake()->numerify('01-##-N#####'),
        ]);
    }
}
