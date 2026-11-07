<?php

namespace App\Services;

use App\Enums\CashMovementType;
use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\Journal;
use App\Enums\MovementStatus;
use App\Enums\TreasuryType;
use App\Models\AdvanceAllocation;
use App\Models\CashMovement;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\TreasuryAccount;
use App\Models\User;

/**
 * Écritures automatiques (CLAUDE.md, « Écritures comptables automatiques »), en USD,
 * les montants en CDF étant convertis au taux de l'opération.
 *
 * | Émission de facture      | 411 (TTC)                       | 706 (HT après réduction), 4431 TVA |
 * | Avoir                    | 706, 4431                       | 411                                |
 * | Encaissement sur facture | 5711, 5712, 521 ou 5216         | 411                                |
 * | Provision sans facture   | trésorerie                      | 4191                               |
 * | Imputation de provision  | 4191                            | 411                                |
 * | Décaissement             | compte de charge ou 4711        | trésorerie                         |
 * | Change                   | trésorerie reçue                | trésorerie cédée                   |
 * | Écart de caisse          | 658 / trésorerie ou trésorerie / 758                                 |
 * | Solde d'ouverture        | trésorerie                      | 12 Report à nouveau                |
 */
class AccountingPoster
{
    public const ISSUE = 'issue';

    public const CANCEL = 'cancel';

    public const MOVEMENT = 'movement';

    public const ALLOCATION = 'allocation';

    public function __construct(private readonly LedgerService $ledger) {}

    public function invoiceIssued(Invoice $invoice, ?User $by = null): ?JournalEntry
    {
        if ($this->posted($invoice, self::ISSUE)) {
            return null;
        }

        $rate = (string) $invoice->exchange_rate;
        $total = LedgerService::toUsd((string) $invoice->total, $invoice->currency, $rate);
        $vat = LedgerService::toUsd((string) $invoice->vat_amount, $invoice->currency, $rate);
        $ht = bcsub($total, $vat, 2);
        $htOriginal = bcsub((string) $invoice->total, (string) $invoice->vat_amount, 2);
        $cur = $invoice->currency;
        $invoice->loadMissing('client');

        return $this->ledger->post(Journal::Sales, $invoice->issue_date, 'Facture '.$invoice->number.', '.$invoice->client->name, [
            ['code' => '411', 'debit' => $total, 'currency' => $cur, 'amount' => (string) $invoice->total, 'rate' => $rate, 'label' => $invoice->client->name],
            ['code' => '706', 'credit' => $ht, 'currency' => $cur, 'amount' => $htOriginal, 'rate' => $rate],
            ['code' => '4431', 'credit' => $vat, 'currency' => $cur, 'amount' => (string) $invoice->vat_amount, 'rate' => $rate],
        ], $invoice->number, $invoice, self::ISSUE, $by);
    }

    public function creditNote(CreditNote $credit, ?User $by = null): ?JournalEntry
    {
        if ($this->posted($credit, self::CANCEL)) {
            return null;
        }

        $rate = (string) $credit->exchange_rate;
        $total = LedgerService::toUsd((string) $credit->total, $credit->currency, $rate);
        $vat = LedgerService::toUsd((string) $credit->vat_amount, $credit->currency, $rate);
        $credit->loadMissing(['invoice', 'client']);

        return $this->ledger->post(Journal::Sales, $credit->issue_date, 'Avoir '.$credit->number.' sur '.$credit->invoice->number, [
            ['code' => '706', 'debit' => bcsub($total, $vat, 2), 'currency' => $credit->currency, 'amount' => bcsub((string) $credit->total, (string) $credit->vat_amount, 2), 'rate' => $rate],
            ['code' => '4431', 'debit' => $vat, 'currency' => $credit->currency, 'amount' => (string) $credit->vat_amount, 'rate' => $rate],
            ['code' => '411', 'credit' => $total, 'currency' => $credit->currency, 'amount' => (string) $credit->total, 'rate' => $rate, 'label' => $credit->client->name],
        ], $credit->number, $credit, self::CANCEL, $by);
    }

    /** Mouvement de caisse validé (un décaissement en attente n'est comptabilisé qu'à sa validation). */
    public function movement(CashMovement $m, ?User $by = null): ?JournalEntry
    {
        if ($m->status !== MovementStatus::Validated || $this->posted($m, self::MOVEMENT)) {
            return null;
        }

        $m->loadMissing(['account', 'counterAccount', 'invoice', 'client']);
        $rate = (string) $m->exchange_rate;
        $usd = LedgerService::toUsd((string) $m->amount, $m->currency, $rate);
        $treasury = ['code' => $m->account->ledger_code, 'currency' => $m->currency, 'amount' => (string) $m->amount, 'rate' => $rate, 'label' => $m->account->name];
        $date = $m->occurred_at;
        $journal = $this->journalFor($m->account);

        if ($m->type === CashMovementType::Reversal) {
            $original = JournalEntry::query()->where('source_type', $m->getMorphClass())->where('source_id', $m->reverses_id)->where('source_event', self::MOVEMENT)->first();

            return $original ? $this->ledger->reverse($original, 'Contre-passation : '.$original->label, $m, self::MOVEMENT, $by, $date) : null;
        }

        [$journal, $lines] = match ($m->type) {
            CashMovementType::Receipt => [$journal, [
                $treasury + ['debit' => $usd],
                $m->invoice
                    ? ['code' => '411', 'credit' => $usd, 'currency' => $m->invoice->currency, 'amount' => (string) $m->invoice_amount, 'rate' => $rate, 'label' => $m->client?->name]
                    : ['code' => '4191', 'credit' => $usd, 'currency' => $m->currency, 'amount' => (string) $m->amount, 'rate' => $rate, 'label' => $m->client?->name],
            ]],
            CashMovementType::Disbursement => [$journal, [
                ['code' => $m->category->ledgerCode(), 'debit' => $usd, 'currency' => $m->currency, 'amount' => (string) $m->amount, 'rate' => $rate, 'label' => $m->beneficiary],
                $treasury + ['credit' => $usd],
            ]],
            CashMovementType::Exchange => [Journal::Cash, [
                ['code' => $m->counterAccount->ledger_code, 'debit' => $usd, 'currency' => $m->counter_currency, 'amount' => (string) $m->counter_amount, 'rate' => $rate, 'label' => $m->counterAccount->name],
                $treasury + ['credit' => $usd],
            ]],
            CashMovementType::Adjustment => [Journal::Cash, $m->direction === CashMovement::IN
                ? [$treasury + ['debit' => $usd], ['code' => '758', 'credit' => $usd, 'currency' => $m->currency, 'amount' => (string) $m->amount, 'rate' => $rate]]
                : [['code' => '658', 'debit' => $usd, 'currency' => $m->currency, 'amount' => (string) $m->amount, 'rate' => $rate], $treasury + ['credit' => $usd]],
            ],
            CashMovementType::OpeningBalance => [Journal::Misc, [
                $treasury + ['debit' => $usd],
                ['code' => '12', 'credit' => $usd, 'currency' => $m->currency, 'amount' => (string) $m->amount, 'rate' => $rate],
            ]],
        };

        return $this->ledger->post($journal, $date, $m->label.($m->client ? ', '.$m->client->name : ''), $lines,
            $m->receipt_number ?? $m->voucher, $m, self::MOVEMENT, $by);
    }

    /** Imputation d'une provision sur une facture : 4191 → 411 (FAC-13). */
    public function allocation(AdvanceAllocation $allocation, ?User $by = null): ?JournalEntry
    {
        if ($this->posted($allocation, self::ALLOCATION)) {
            return null;
        }

        $allocation->loadMissing(['advance.client', 'invoice']);
        $advance = $allocation->advance;
        $rate = (string) $allocation->exchange_rate;
        $usd = LedgerService::toUsd((string) $allocation->amount, $advance->currency, $rate);

        return $this->ledger->post(Journal::Misc, $allocation->created_at,
            "Imputation de la provision {$advance->receipt_number} sur {$allocation->invoice->number}", [
                ['code' => '4191', 'debit' => $usd, 'currency' => $advance->currency, 'amount' => (string) $allocation->amount, 'rate' => $rate, 'label' => $advance->client->name],
                ['code' => '411', 'credit' => $usd, 'currency' => $allocation->invoice->currency, 'amount' => (string) $allocation->invoice_amount, 'rate' => $rate, 'label' => $advance->client->name],
            ], $allocation->invoice->number, $allocation, self::ALLOCATION, $by);
    }

    /**
     * Reprise : comptabilise, dans l'ordre chronologique, les opérations qui n'ont pas encore
     * d'écriture (données importées ou de démonstration). Idempotent.
     *
     * @return int nombre d'écritures créées
     */
    public function backfill(): int
    {
        $events = collect();

        Invoice::query()->whereNotNull('issue_date')->get()
            ->each(fn ($i) => $events->push([$i->issue_date->copy()->setTimeFrom($i->issued_at ?? $i->issue_date), fn () => $this->invoiceIssued($i)]));
        CreditNote::query()->get()
            ->each(fn ($c) => $events->push([$c->created_at, fn () => $this->creditNote($c)]));
        CashMovement::query()->where('status', MovementStatus::Validated->value)->get()
            ->each(fn ($m) => $events->push([$m->occurred_at, fn () => $this->movement($m)]));
        AdvanceAllocation::query()->get()
            ->each(fn ($a) => $events->push([$a->created_at, fn () => $this->allocation($a)]));

        return $events->sortBy(fn ($e) => $e[0]->timestamp)->reduce(fn (int $n, $e) => $n + ($e[1]() ? 1 : 0), 0);
    }

    private function posted(object $source, string $event): bool
    {
        return JournalEntry::query()->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->where('source_event', $event)->exists();
    }

    private function journalFor(TreasuryAccount $account): Journal
    {
        return $account->type === TreasuryType::Cash ? Journal::Cash : Journal::Bank;
    }
}
