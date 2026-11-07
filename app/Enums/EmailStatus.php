<?php

namespace App\Enums;

enum EmailStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case NotSent = 'not_sent';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En file d\'attente',
            self::Sent => 'Envoyé',
            self::Failed => 'Échec',
            self::NotSent => 'Non envoyé',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Queued => 'bg-sky-50 text-sky-800',
            self::Sent => 'bg-emerald-50 text-emerald-800',
            self::Failed => 'bg-red-50 text-red-800',
            self::NotSent => 'bg-amber-50 text-amber-900',
        };
    }

    /** Un e-mail en échec ou non envoyé peut être relancé à la main (NOT-07). */
    public function canResend(): bool
    {
        return in_array($this, [self::Failed, self::NotSent], true);
    }
}
