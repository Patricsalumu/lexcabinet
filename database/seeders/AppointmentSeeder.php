<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\LocationType;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Rendez-vous de démonstration du prototype (dates relatives à aujourd'hui).
 * Créés sans e-mail : seuls les rendez-vous saisis dans l'application déclenchent les envois.
 */
class AppointmentSeeder extends Seeder
{
    public function run(): void
    {
        $user = fn (string $email) => User::query()->where('email', $email.'@mukendi-avocats.example')->firstOrFail();
        $client = fn (string $name) => Client::query()->where('name', $name)->firstOrFail();
        $case = fn (string $suffix) => LegalCase::query()->where('reference', 'like', "%-{$suffix}")->firstOrFail();
        $at = fn (int $offset, string $time) => Carbon::today()->addDays($offset)->setTimeFromTimeString($time);

        $grace = $user('grace.mukendi');
        $joel = $user('joel.kabeya');
        $sarah = $user('sarah.ilunga');
        $nadine = $user('nadine.lukusa');

        $rows = [
            [$client('Rachel Mwamba'), $grace, $at(0, '09:00'), 60, AppointmentType::Consultation, LocationType::Office, null, 'Premier rendez-vous : bail commercial', AppointmentStatus::Confirmed, null],
            [$client('Kivu Agro SARL'), $joel, $at(0, '11:30'), 120, AppointmentType::Hearing, LocationType::Court, 'Tribunal de commerce, chambre 2', 'Plaidoiries RCE 1123', AppointmentStatus::Planned, $case('0038')],
            [$client('Jean-Pierre Lumbala'), $sarah, $at(0, '15:00'), 60, AppointmentType::FollowUp, LocationType::Office, null, 'Point sur le litige parcellaire', AppointmentStatus::Confirmed, $case('0041')],
            [$client('Esther Kalala'), $joel, $at(1, '10:00'), 60, AppointmentType::FollowUp, LocationType::Video, null, 'Stratégie avant la conciliation', AppointmentStatus::Planned, $case('0044')],
            [$client('Congo Express Transports SARL'), $grace, $at(1, '14:30'), 90, AppointmentType::Consultation, LocationType::Office, null, 'Revue des statuts et du pacte d\'associés', AppointmentStatus::Confirmed, $case('0029')],
            [$client('Patrice Ngoy'), $sarah, $at(3, '09:30'), 120, AppointmentType::Hearing, LocationType::Court, 'Tribunal de paix de Kamalondo', 'Prononcé RP 3318', AppointmentStatus::Planned, $case('0046')],
            [$client('Jean-Pierre Lumbala'), $sarah, $at(-1, '10:00'), 60, AppointmentType::Consultation, LocationType::Office, null, 'Analyse du certificat', AppointmentStatus::Honored, $case('0041')],
            [$client('Esther Kalala'), $joel, $at(-2, '16:00'), 30, AppointmentType::Signing, LocationType::Office, null, 'Signature de la convention d\'honoraires', AppointmentStatus::NoShow, $case('0044')],
        ];

        foreach ($rows as [$c, $lawyer, $start, $minutes, $type, $location, $detail, $subject, $status, $legalCase]) {
            Appointment::query()->forceCreate([
                'client_id' => $c->id,
                'case_id' => $legalCase?->id,
                'lawyer_id' => $lawyer->id,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes($minutes),
                'type' => $type,
                'location_type' => $location,
                'location_detail' => $detail,
                'subject' => $subject,
                'status' => $status,
                'created_by' => $nadine->id,
                'created_at' => $start->copy()->subDays(5),
            ]);
        }
    }
}
