<?php

namespace App\Enums;

enum RateSource: string
{
    case Manual = 'manual';
    case Bcc = 'bcc';
    case Market = 'market';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Saisie manuelle',
            self::Bcc => 'Banque Centrale du Congo',
            self::Market => 'Taux marché',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $source) {
            $options[$source->value] = $source->label();
        }

        return $options;
    }
}
