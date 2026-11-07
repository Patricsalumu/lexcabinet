<?php

namespace App\Enums;

/** DOS-03 */
enum CaseStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Deliberation = 'deliberation';
    case Closed = 'closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouvert',
            self::InProgress => 'En cours',
            self::Deliberation => 'En délibéré',
            self::Closed => 'Clôturé',
            self::Archived => 'Archivé',
        };
    }

    /** Un dossier clôturé ou archivé n'est plus actif (arrêt de l'abonnement, DOS-08). */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Closed, self::Archived], true);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Open => 'bg-sky-50 text-sky-800',
            self::InProgress => 'bg-indigo-50 text-indigo-900',
            self::Deliberation => 'bg-amber-50 text-amber-900',
            self::Closed => 'bg-slate-100 text-slate-700',
            self::Archived => 'bg-slate-100 text-slate-500',
        };
    }
}
