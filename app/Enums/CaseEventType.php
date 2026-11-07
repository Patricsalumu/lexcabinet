<?php

namespace App\Enums;

enum CaseEventType: string
{
    case Act = 'act';
    case Hearing = 'hearing';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Act => 'Acte',
            self::Hearing => 'Audience',
            self::Note => 'Note',
        };
    }
}
