<?php

namespace App\Enums;

enum MovementStatus: string
{
    case Validated = 'validated';
    case Pending = 'pending';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Validated => 'Validé',
            self::Pending => 'En attente',
            self::Rejected => 'Refusé',
        };
    }
}
