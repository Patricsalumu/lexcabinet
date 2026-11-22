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
        // Hors contexte de cabinet (connexion, mot de passe oublié…), l'entrée est rangée dans
        // le cabinet de la donnée concernée ; à défaut, elle relève du journal de la plateforme.
        static::creating(function (AuditActivity $activity) {
            // Les actions de la plateforme ne relèvent d'aucun cabinet.
            if ($activity->log_name === 'platform') {
                $activity->firm_id = null;

                return;
            }

            if ($activity->firm_id === null) {
                $owner = $activity->subject ?? $activity->causer;
                $activity->firm_id = $owner?->getAttribute('firm_id');
            }
        });

        static::updating(function () {
            throw new LogicException("Le journal d'audit n'est pas modifiable.");
        });

        static::deleting(function () {
            throw new LogicException("Le journal d'audit n'est pas supprimable.");
        });
    }
}
