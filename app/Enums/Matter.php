<?php

namespace App\Enums;

/** DOS-02, complété par les matières du prototype (administratif, famille). */
enum Matter: string
{
    case Civil = 'civil';
    case Commercial = 'commercial';
    case Criminal = 'criminal';
    case Labour = 'labour';
    case Land = 'land';
    case Ohada = 'ohada';
    case Advisory = 'advisory';
    case Administrative = 'administrative';
    case Family = 'family';

    public function label(): string
    {
        return match ($this) {
            self::Civil => 'Civil',
            self::Commercial => 'Commercial',
            self::Criminal => 'Pénal',
            self::Labour => 'Social',
            self::Land => 'Foncier',
            self::Ohada => 'OHADA',
            self::Advisory => 'Conseil',
            self::Administrative => 'Administratif',
            self::Family => 'Famille',
        };
    }
}
