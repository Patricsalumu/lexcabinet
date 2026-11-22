<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * Entrée du journal d'audit : non modifiable et non supprimable depuis l'application.
 */
class AuditActivity extends Activity
{
    use BelongsToFirm;

    /** Peut exister hors cabinet (super administrateur, actions de la plateforme). */
    protected static bool $firmOptional = true;

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException("Le journal d'audit n'est pas modifiable.");
        });

        static::deleting(function () {
            throw new LogicException("Le journal d'audit n'est pas supprimable.");
        });
    }
}
