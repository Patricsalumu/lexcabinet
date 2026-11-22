<?php

namespace App\Services;

use App\Models\Counter;
use App\Support\Tenancy\CurrentFirm;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Numérotation continue et sans trou : verrou lockForUpdate sur un compteur.
 * Doit être appelé dans la transaction qui crée l'enregistrement numéroté,
 * pour qu'un échec annule aussi l'incrément.
 */
class NumberingService
{
    public function next(string $key): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La numérotation doit se faire dans une transaction.');
        }

        Counter::query()->insertOrIgnore(['firm_id' => app(CurrentFirm::class)->require(), 'key' => $key, 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $counter = Counter::query()->where('key', $key)->lockForUpdate()->firstOrFail();
        $counter->value++;
        $counter->save();

        return $counter->value;
    }

    /** Positionne un compteur (reprise de numérotation existante, données de démonstration). */
    public function set(string $key, int $value): void
    {
        Counter::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Référence de dossier : LEX-2026-0042 (DOS-01). */
    public function nextCaseReference(int $year): string
    {
        return sprintf('LEX-%d-%04d', $year, $this->next("case:{$year}"));
    }
}
