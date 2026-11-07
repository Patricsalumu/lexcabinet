<?php

namespace App\Models;

use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * Entrée du journal d'audit : non modifiable et non supprimable depuis l'application.
 */
class AuditActivity extends Activity
{
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
