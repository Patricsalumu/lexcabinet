<?php

namespace App\Models\Concerns;

use App\Models\Firm;
use App\Support\Tenancy\CurrentFirm;
use App\Support\Tenancy\FirmScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Donnée appartenant à un cabinet : filtre automatique sur le cabinet courant (lecture,
 * modification, liaison de route) et firm_id renseigné tout seul à la création.
 *
 * Un modèle peut déclarer `protected static bool $firmOptional = true;` (utilisateurs,
 * journal d'audit) : il peut alors exister hors cabinet et n'est pas filtré sans cabinet courant.
 */
trait BelongsToFirm
{
    public static function bootBelongsToFirm(): void
    {
        $optional = static::firmIsOptional();

        static::addGlobalScope(new FirmScope(strict: ! $optional));

        static::creating(function ($model) use ($optional) {
            if ($model->firm_id !== null) {
                $current = app(CurrentFirm::class)->id();
                if ($current !== null && (int) $model->firm_id !== $current) {
                    throw new LogicException('Création refusée : la donnée appartient à un autre cabinet que le cabinet courant.');
                }

                return;
            }

            $model->firm_id = $optional ? app(CurrentFirm::class)->id() : app(CurrentFirm::class)->require();
        });

        // Une donnée ne change jamais de cabinet.
        static::updating(function ($model) {
            if ($model->isDirty('firm_id') && $model->getOriginal('firm_id') !== null) {
                throw new LogicException('Une donnée ne peut pas changer de cabinet.');
            }
        });
    }

    protected static function firmIsOptional(): bool
    {
        return property_exists(static::class, 'firmOptional') && static::$firmOptional;
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
