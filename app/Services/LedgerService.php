<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\Journal;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grand livre (CPT-02 à CPT-06) : écritures en USD, équilibrées, dans une période ouverte,
 * numérotées par journal ; jamais modifiées, corrigées par contre-passation.
 */
class LedgerService
{
    public function __construct(private readonly NumberingService $numbering) {}

    /**
     * @param  list<array{code: string, debit?: string, credit?: string, label?: ?string, currency?: Currency|string, amount?: string, rate?: string}>  $lines
     */
    public function post(
        Journal $journal,
        Carbon|string $date,
        string $label,
        array $lines,
        ?string $reference = null,
        ?Model $source = null,
        ?string $event = null,
        ?User $by = null,
        ?int $reverses = null,
        bool $manual = false,
    ): JournalEntry {
        $date = Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($journal, $date, $label, $lines, $reference, $source, $event, $by, $reverses, $manual) {
            $this->assertPeriodOpen($date);

            $prepared = $this->prepare($lines);
            $year = $date->year;

            $entry = JournalEntry::query()->create([
                'number' => sprintf('%s-%d-%05d', $journal->value, $year, $this->numbering->next("entry:{$journal->value}:{$year}")),
                'journal' => $journal,
                'entry_date' => $date->toDateString(),
                'label' => mb_substr($label, 0, 255),
                'reference' => $reference,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'source_event' => $event,
                'reverses_id' => $reverses,
                'is_manual' => $manual,
                'created_by' => $by?->id,
            ]);

            foreach ($prepared as $line) {
                $entry->lines()->create($line);
            }

            activity('accounting')->causedBy($by)->performedOn($entry)
                ->withProperties(['total' => collect($prepared)->reduce(fn ($s, $l) => bcadd($s, $l['debit'], 2), '0')])
                ->log("Écriture {$entry->number} : {$entry->label}");

            return $entry;
        });
    }

    /** Contre-passation : même écriture, débits et crédits inversés (CAI-08, écritures manuelles). */
    public function reverse(JournalEntry $entry, string $label, ?Model $source = null, ?string $event = null, ?User $by = null, Carbon|string|null $date = null): JournalEntry
    {
        if ($entry->reversal()->exists()) {
            throw ValidationException::withMessages(['entry' => "L'écriture {$entry->number} est déjà contre-passée."]);
        }

        $lines = $entry->lines()->with('account')->get()->map(fn ($l) => [
            'code' => $l->account->code,
            'debit' => (string) $l->credit,
            'credit' => (string) $l->debit,
            'label' => $l->label,
            'currency' => $l->original_currency,
            'amount' => (string) $l->original_amount,
            'rate' => (string) $l->exchange_rate,
        ])->all();

        return $this->post($entry->journal, $date ?? today(), $label, $lines, $entry->reference, $source, $event, $by, $entry->id, $entry->is_manual);
    }

    public function assertPeriodOpen(Carbon $date): void
    {
        $closed = AccountingPeriod::query()
            ->where('year', $date->year)->where('month', $date->month)
            ->where('status', AccountingPeriod::CLOSED)->exists();

        if ($closed) {
            throw ValidationException::withMessages([
                'period' => 'La période de '.$date->translatedFormat('F Y').' est clôturée : elle n\'accepte plus d\'écriture.',
            ]);
        }
    }

    /**
     * Clôture mensuelle (CPT-06) : le mois doit être écoulé et la balance équilibrée.
     */
    public function closePeriod(int $year, int $month, User $by): AccountingPeriod
    {
        return DB::transaction(function () use ($year, $month, $by) {
            AccountingPeriod::query()->insertOrIgnore(['year' => $year, 'month' => $month, 'status' => AccountingPeriod::OPEN, 'created_at' => now(), 'updated_at' => now()]);
            $period = AccountingPeriod::query()->where('year', $year)->where('month', $month)->lockForUpdate()->firstOrFail();

            if ($period->isClosed()) {
                throw ValidationException::withMessages(['period' => $period->label().' est déjà clôturée.']);
            }
            if ($period->end()->gte(today())) {
                throw ValidationException::withMessages(['period' => 'On ne clôture qu\'un mois entièrement écoulé.']);
            }

            [$debit, $credit] = $this->totals($period->start(), $period->end());
            if (bccomp($debit, $credit, 2) !== 0) {
                throw ValidationException::withMessages(['period' => 'La balance de '.$period->label().' n\'est pas équilibrée : clôture impossible.']);
            }

            $period->fill(['status' => AccountingPeriod::CLOSED, 'closed_at' => now(), 'closed_by' => $by->id])->save();
            activity('accounting')->causedBy($by)->performedOn($period)
                ->withProperties(['debit' => $debit, 'credit' => $credit])
                ->log('Période '.$period->label().' clôturée');

            return $period;
        });
    }

    /** @return array{0: string, 1: string} total des débits et des crédits sur la période */
    public function totals(Carbon $from, Carbon $to): array
    {
        $row = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('coalesce(sum(debit), 0) as d, coalesce(sum(credit), 0) as c')
            ->first();

        return [Money::normalize((string) $row->d), Money::normalize((string) $row->c)];
    }

    /** Montant converti en USD au taux de l'opération (CPT-04). */
    public static function toUsd(string $amount, Currency|string $currency, string $rate): string
    {
        $currency = $currency instanceof Currency ? $currency : Currency::from($currency);

        return $currency === Currency::USD
            ? Money::normalize($amount)
            : Money::round(bcdiv($amount, $rate, 6));
    }

    /**
     * Vérifie les comptes et l'équilibre (débit = crédit), et prépare les lignes.
     *
     * @return list<array<string, mixed>>
     */
    private function prepare(array $lines): array
    {
        $prepared = [];
        $debit = '0';
        $credit = '0';

        foreach ($lines as $line) {
            $d = Money::normalize((string) ($line['debit'] ?? '0'));
            $c = Money::normalize((string) ($line['credit'] ?? '0'));

            if (bccomp($d, '0', 2) === 0 && bccomp($c, '0', 2) === 0) {
                continue; // ligne nulle (ex. TVA à zéro)
            }
            if (bccomp($d, '0', 2) < 0 || bccomp($c, '0', 2) < 0 || (bccomp($d, '0', 2) > 0 && bccomp($c, '0', 2) > 0)) {
                throw ValidationException::withMessages(['lines' => 'Chaque ligne porte soit un débit, soit un crédit, positif.']);
            }

            $account = Account::query()->where('code', $line['code'])->first();
            if ($account === null || ! $account->is_active) {
                throw ValidationException::withMessages(['lines' => "Le compte {$line['code']} n'existe pas ou est désactivé dans le plan comptable."]);
            }

            $currency = $line['currency'] ?? Currency::USD;
            $prepared[] = [
                'account_id' => $account->id,
                'label' => $line['label'] ?? null,
                'debit' => $d,
                'credit' => $c,
                'original_currency' => $currency instanceof Currency ? $currency : Currency::from($currency),
                'original_amount' => Money::normalize((string) ($line['amount'] ?? bcadd($d, $c, 2))),
                'exchange_rate' => Money::normalize((string) ($line['rate'] ?? '1'), 4),
            ];

            $debit = bcadd($debit, $d, 2);
            $credit = bcadd($credit, $c, 2);
        }

        if (count($prepared) < 2 || bccomp($debit, $credit, 2) !== 0) {
            throw ValidationException::withMessages([
                'lines' => 'Écriture déséquilibrée : débits '.Money::format($debit, Currency::USD).', crédits '.Money::format($credit, Currency::USD).'.',
            ]);
        }

        return $prepared;
    }
}
