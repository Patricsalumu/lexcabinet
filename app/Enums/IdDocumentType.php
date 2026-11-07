<?php

namespace App\Enums;

enum IdDocumentType: string
{
    case VoterCard = 'voter_card';
    case Passport = 'passport';
    case NationalId = 'national_id';
    case DrivingLicence = 'driving_licence';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::VoterCard => 'Carte d\'électeur',
            self::Passport => 'Passeport',
            self::NationalId => 'Carte d\'identité',
            self::DrivingLicence => 'Permis de conduire',
            self::Other => 'Autre pièce',
        };
    }
}
