<?php

namespace App\Enums;

/** RDV-04 */
enum AppointmentStatus: string
{
    case Planned = 'planned';
    case Confirmed = 'confirmed';
    case Honored = 'honored';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Postponed = 'postponed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planifié',
            self::Confirmed => 'Confirmé',
            self::Honored => 'Honoré',
            self::Cancelled => 'Annulé',
            self::NoShow => 'Absent',
            self::Postponed => 'Reporté',
        };
    }

    /** Un rendez-vous annulé ou reporté libère le créneau (RDV-03). */
    public function occupiesSlot(): bool
    {
        return ! in_array($this, [self::Cancelled, self::Postponed], true);
    }

    /** Les rappels ne partent que pour un rendez-vous encore à venir. */
    public function expectsReminder(): bool
    {
        return in_array($this, [self::Planned, self::Confirmed], true);
    }

    /** @return list<string> */
    public static function slotValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->occupiesSlot())));
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Planned => 'bg-sky-50 text-sky-800',
            self::Confirmed => 'bg-indigo-50 text-indigo-900',
            self::Honored => 'bg-emerald-50 text-emerald-800',
            self::Cancelled => 'bg-slate-100 text-slate-500 line-through',
            self::NoShow => 'bg-red-50 text-red-800',
            self::Postponed => 'bg-amber-50 text-amber-900',
        };
    }
}
