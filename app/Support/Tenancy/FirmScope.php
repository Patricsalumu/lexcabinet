<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtre toutes les requêtes Eloquent sur le cabinet courant.
 *
 * Mode strict (données métier) : sans cabinet courant, la requête ne renvoie rien.
 * Mode souple (utilisateurs, journal d'audit) : sans cabinet courant, pas de filtre ;
 * nécessaire à la connexion, qui précède la connaissance du cabinet.
 */
final class FirmScope implements Scope
{
    public function __construct(private readonly bool $strict = true) {}

    public function apply(Builder $builder, Model $model): void
    {
        $firmId = app(CurrentFirm::class)->id();

        if ($firmId !== null) {
            $builder->where($model->qualifyColumn('firm_id'), $firmId);
        } elseif ($this->strict) {
            $builder->whereRaw('1 = 0');
        }
    }
}
