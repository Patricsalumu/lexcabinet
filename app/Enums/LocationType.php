<?php

namespace App\Enums;

/** RDV-01 : cabinet, tribunal, visioconférence (+ « chez le client », repris du prototype). */
enum LocationType: string
{
    case Office = 'office';
    case Court = 'court';
    case Video = 'video';
    case ClientPremises = 'client_premises';

    public function label(): string
    {
        return match ($this) {
            self::Office => 'Cabinet',
            self::Court => 'Tribunal',
            self::Video => 'Visioconférence',
            self::ClientPremises => 'Chez le client',
        };
    }
}
