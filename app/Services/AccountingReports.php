<?php

namespace App\Services;

use App\Enums\Journal;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * États comptables par période (CPT-05) : journal, grand livre, balance, compte de résultat simplifié.
 * Tous les montants sont en USD.
 */
class AccountingReports
{
    /** @return Collection<int, JournalEntry> */
    public function journal(Carbon $from, Carbon $to, ?Journal $journal = null): Collection
    {
        return JournalEntry::query()
            ->with(['lines.account'])
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->when($journal, fn ($q) => $q->where('journal', $journal->value))
            ->orderBy('entry_date')->orderBy('id')
            ->get();
    }

    /**
     * Grand livre : pour chaque compte mouvementé, solde d'ouverture, lignes et solde cumulé.
     *
     * @return list<array{account: Account, opening: string, lines: list<array<string, mixed>>, debit: string, credit: string, closing: string}>
     */
    public function generalLedger(Carbon $from, Carbon $to, ?int $accountId = null): array
    {
        $opening = $this->sums(null, $from->copy()->subDay())->keyBy('account_id');
        $lines = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->when($accountId, fn ($q) => $q->where('journal_lines.account_id', $accountId))
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.id')
            ->get(['journal_lines.*', 'journal_entries.number', 'journal_entries.journal', 'journal_entries.entry_date', 'journal_entries.label as entry_label', 'journal_entries.reference'])
            ->groupBy('account_id');

        $ids = $lines->keys()->merge($accountId ? [$accountId] : [])->unique();
        $accounts = Account::query()->whereKey($ids)->orderBy('code')->get();

        return $accounts->map(function (Account $account) use ($opening, $lines) {
            $open = $opening->has($account->id) ? bcsub($opening[$account->id]->d, $opening[$account->id]->c, 2) : '0.00';
            $running = $open;
            $debit = '0';
            $credit = '0';
            $rows = [];

            foreach ($lines->get($account->id, collect()) as $l) {
                $running = bcadd($running, bcsub($l->debit, $l->credit, 2), 2);
                $debit = bcadd($debit, $l->debit, 2);
                $credit = bcadd($credit, $l->credit, 2);
                $rows[] = [
                    'date' => Carbon::parse($l->entry_date),
                    'number' => $l->number,
                    'journal' => $l->journal,
                    'reference' => $l->reference,
                    'label' => $l->label ? $l->entry_label.' — '.$l->label : $l->entry_label,
                    'debit' => Money::normalize($l->debit),
                    'credit' => Money::normalize($l->credit),
                    'balance' => $running,
                ];
            }

            return [
                'account' => $account,
                'opening' => Money::normalize($open),
                'lines' => $rows,
                'debit' => Money::normalize($debit),
                'credit' => Money::normalize($credit),
                'closing' => Money::normalize($running),
            ];
        })->values()->all();
    }

    /**
     * Balance de la période : totaux débit et crédit, solde débiteur ou créditeur par compte.
     *
     * @return array{rows: list<array<string, mixed>>, debit: string, credit: string, balance_debit: string, balance_credit: string, balanced: bool}
     */
    public function trialBalance(Carbon $from, Carbon $to): array
    {
        $sums = $this->sums($from, $to);
        $accounts = Account::query()->whereKey($sums->pluck('account_id'))->get()->keyBy('id');

        $rows = [];
        $totals = ['debit' => '0', 'credit' => '0', 'balance_debit' => '0', 'balance_credit' => '0'];

        foreach ($sums->sortBy(fn ($s) => $accounts[$s->account_id]->code, SORT_STRING) as $s) {
            $balance = bcsub($s->d, $s->c, 2);
            $row = [
                'account' => $accounts[$s->account_id],
                'debit' => Money::normalize($s->d),
                'credit' => Money::normalize($s->c),
                'balance_debit' => bccomp($balance, '0', 2) > 0 ? Money::normalize($balance) : '0.00',
                'balance_credit' => bccomp($balance, '0', 2) < 0 ? Money::normalize(ltrim($balance, '-')) : '0.00',
            ];
            foreach (array_keys($totals) as $k) {
                $totals[$k] = bcadd($totals[$k], $row[$k], 2);
            }
            $rows[] = $row;
        }

        return ['rows' => $rows]
            + array_map(fn ($v) => Money::normalize($v), $totals)
            + ['balanced' => bccomp($totals['debit'], $totals['credit'], 2) === 0];
    }

    /**
     * Compte de résultat simplifié : produits (classe 7) moins charges (classe 6).
     *
     * @return array{products: list<array{account: Account, amount: string}>, charges: list<array{account: Account, amount: string}>, total_products: string, total_charges: string, result: string}
     */
    public function incomeStatement(Carbon $from, Carbon $to): array
    {
        $sums = $this->sums($from, $to);
        $accounts = Account::query()->whereKey($sums->pluck('account_id'))->get()->keyBy('id');

        $products = [];
        $charges = [];
        foreach ($sums->sortBy(fn ($s) => $accounts[$s->account_id]->code, SORT_STRING) as $s) {
            $account = $accounts[$s->account_id];
            if ($account->class === 7) {
                $products[] = ['account' => $account, 'amount' => Money::normalize(bcsub($s->c, $s->d, 2))];
            } elseif ($account->class === 6) {
                $charges[] = ['account' => $account, 'amount' => Money::normalize(bcsub($s->d, $s->c, 2))];
            }
        }

        $total = fn (array $list) => Money::normalize(array_reduce($list, fn ($s, $r) => bcadd($s, $r['amount'], 2), '0'));
        $totalProducts = $total($products);
        $totalCharges = $total($charges);

        return [
            'products' => $products,
            'charges' => $charges,
            'total_products' => $totalProducts,
            'total_charges' => $totalCharges,
            'result' => Money::normalize(bcsub($totalProducts, $totalCharges, 2)),
        ];
    }

    /** Sommes des débits et crédits par compte, sur une période (ou depuis l'origine). */
    private function sums(?Carbon $from, Carbon $to): Collection
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->where('journal_entries.entry_date', '>=', $from->toDateString()))
            ->where('journal_entries.entry_date', '<=', $to->toDateString())
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, sum(debit) as d, sum(credit) as c')
            ->get()
            ->map(function ($s) {
                $s->d = Money::normalize((string) $s->d);
                $s->c = Money::normalize((string) $s->c);

                return $s;
            });
    }
}
