<?php

namespace App\Console\Concerns;

use App\Models\Firm;
use App\Support\Tenancy\CurrentFirm;
use Throwable;

/**
 * Commandes planifiées multi-cabinet : le traitement est exécuté une fois par cabinet actif,
 * dans le contexte de ce cabinet. L'échec d'un cabinet n'empêche pas le traitement des autres.
 * Option --firm=ID pour ne traiter qu'un cabinet.
 */
trait RunsForEachFirm
{
    /**
     * @param  callable(Firm): string  $callback  renvoie le compte rendu du cabinet
     */
    protected function forEachFirm(callable $callback): int
    {
        $firms = Firm::query()->active()
            ->when($this->option('firm'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')->get();

        $failures = 0;
        foreach ($firms as $firm) {
            try {
                $report = app(CurrentFirm::class)->run($firm, fn () => $callback($firm));
                $this->line("[{$firm->name}] {$report}");
            } catch (Throwable $e) {
                $failures++;
                $this->error("[{$firm->name}] Échec : {$e->getMessage()}");
                report($e);
            }
        }

        if ($firms->isEmpty()) {
            $this->warn('Aucun cabinet actif.');
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
