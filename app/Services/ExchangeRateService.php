<?php

namespace App\Services;

use App\Enums\RateSource;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Taux de change journaliers (CAI-07) : nombre de CDF pour 1 USD, un par jour.
 */
class ExchangeRateService
{
    /**
     * Taux applicable à une date : celui du jour, sinon le dernier taux connu avant.
     */
    public function rateFor(CarbonInterface|string|null $date = null): ?ExchangeRate
    {
        $day = $this->day($date);

        return ExchangeRate::query()
            ->whereDate('rate_date', '<=', $day->toDateString())
            ->orderByDesc('rate_date')
            ->first();
    }

    /** Vrai si aucun taux n'a été saisi pour cette date précise. */
    public function isMissingFor(CarbonInterface|string|null $date = null): bool
    {
        $rate = $this->rateFor($date);

        return $rate === null || ! $rate->rate_date->isSameDay($this->day($date));
    }

    /**
     * Enregistre (ou corrige) le taux d'une date. Une date future est refusée.
     */
    public function record(CarbonInterface|string $date, string|int $rate, RateSource $source, ?User $by = null): ExchangeRate
    {
        $day = $this->day($date);

        if ($day->isAfter(Carbon::today())) {
            throw new InvalidArgumentException('Impossible de saisir un taux pour une date future.');
        }

        $value = Money::normalize($rate, 4);
        if (bccomp($value, '0', 4) <= 0) {
            throw new InvalidArgumentException('Le taux doit être strictement positif.');
        }

        return DB::transaction(function () use ($day, $value, $source, $by) {
            $existing = ExchangeRate::query()
                ->whereDate('rate_date', $day->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update(['rate' => $value, 'source' => $source]);

                return $existing;
            }

            return ExchangeRate::query()->create([
                'rate_date' => $day->toDateString(),
                'rate' => $value,
                'source' => $source,
                'created_by' => $by?->id,
            ]);
        });
    }

    private function day(CarbonInterface|string|null $date): Carbon
    {
        return ($date === null ? Carbon::today() : Carbon::parse($date))->startOfDay();
    }
}
