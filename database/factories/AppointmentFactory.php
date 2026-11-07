<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\LocationType;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $start = Carbon::tomorrow()->setTime(10, 0);

        return [
            'client_id' => Client::factory(),
            'lawyer_id' => User::factory(),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'type' => AppointmentType::Consultation,
            'location_type' => LocationType::Office,
            'subject' => 'Consultation',
            'status' => AppointmentStatus::Planned,
            'created_at' => now()->subDays(3),
        ];
    }

    public function at(Carbon $start, int $minutes = 60): static
    {
        return $this->state(fn () => ['starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($minutes)]);
    }
}
