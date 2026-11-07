<?php

namespace App\Enums;

/** RDV-01 */
enum AppointmentType: string
{
    case Consultation = 'consultation';
    case FollowUp = 'follow_up';
    case Signing = 'signing';
    case Hearing = 'hearing';

    public function label(): string
    {
        return match ($this) {
            self::Consultation => 'Consultation',
            self::FollowUp => 'Suivi de dossier',
            self::Signing => 'Signature',
            self::Hearing => 'Audience',
        };
    }
}
