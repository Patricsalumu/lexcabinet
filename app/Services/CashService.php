<?php

namespace App\Services;

use App\Enums\CashMovementType;
use App\Enums\Currency;
use App\Enums\DisbursementCategory;
use App\Enums\InvoiceStatus;
use App\Enums\MovementStatus;
use App\Enums\PaymentMode;
use App\Enums\Permission;
use App\Enums\TreasuryType;
use App\Models\AdvanceAllocation;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caisse en USD et CDF, banques et mobile money (CAI-01 à CAI-08, FAC-11, FAC-13).
 * Toute opération s'exécute dans une transaction et est tracée dans le journal d'audit.
 */
class CashService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly ExchangeRateService $rates,
        private readonly SettingsService $settings,
        private readonly EmailService $emails,
        private readonly AccountingPoster $accounting,
    ) {}

    // ------------------------------------------------------------------ Soldes

    /** Solde d'un compte : entrées - sorties validées, montants reçus par change compris. */
    public function balance(TreasuryAccount $account): string
    {
        $base = CashMovement::query()->where('status', MovementStatus::Validated->value);

        $in = (string) (clone $base)->where('treasury_account_id', $account->id)->where('direction', CashMovement::IN)->sum('amount');
        $out = (string) (clone $base)->where('treasury_account_id', $account->id)->where('direction', CashMovement::OUT)->sum('amount');
        $counterIn = (string) (clone $base)->where('counter_account_id', $account->id)->sum('counter_amount');

        return Money::normalize(bcadd(bcsub($in, $out, 2), $counterIn, 2));
    }

    public function cashAccount(Currency $currency): TreasuryAccount
    {
        return TreasuryAccount::query()->where('type', TreasuryType::Cash->value)->where('currency', $currency->value)->where('is_active', true)->firstOrFail();
    }

    /** @return array{USD: string, CDF: string} Solde théorique de la caisse physique. */
    public function cashBalances(): array
    {
        $balances = [];
        foreach ([Currency::USD, Currency::CDF] as $currency) {
            $account = TreasuryAccount::query()->where('type', TreasuryType::Cash->value)->where('currency', $currency->value)->where('is_active', true)->first();
            $balances[$currency->value] = $account ? $this->balance($account) : '0.00';
        }

        return $balances;
    }

    public function currentSession(): ?CashSession
    {
        return CashSession::query()->where('status', CashSession::OPEN)->latest('id')->first();
    }

    // ------------------------------------------------------------------ Sessions (CAI-02)

    public function openSession(string $countedUsd, string $countedCdf, User $by): CashSession
    {
        return DB::transaction(function () use ($countedUsd, $countedCdf, $by) {
            $this->lockCashDesk();
            if ($this->currentSession()) {
                throw ValidationException::withMessages(['session' => 'La caisse est déjà ouverte.']);
            }

            $book = $this->cashBalances();
            $session = CashSession::query()->create([
                'status' => CashSession::OPEN,
                'opened_at' => now(),
                'opened_by' => $by->id,
                'opening_usd' => $this->amount($countedUsd, allowZero: true),
                'opening_cdf' => $this->amount($countedCdf, allowZero: true),
                'book_opening_usd' => $book['USD'],
                'book_opening_cdf' => $book['CDF'],
            ]);

            activity('cash')->causedBy($by)->performedOn($session)
                ->withProperties(['counted' => [$session->opening_usd, $session->opening_cdf], 'book' => $book])
                ->log('Ouverture de caisse : '.Money::format((string) $session->opening_usd, Currency::USD).' et '.Money::format((string) $session->opening_cdf, Currency::CDF).' comptés');

            return $session;
        });
    }

    /**
     * Clôture : comptage réel USD et CDF, écart avec le solde théorique, motif obligatoire si écart ;
     * chaque écart génère un mouvement d'ajustement (CAI-02).
     */
    public function closeSession(CashSession $session, string $countedUsd, string $countedCdf, ?string $reason, User $by): CashSession
    {
        return DB::transaction(function () use ($session, $countedUsd, $countedCdf, $reason, $by) {
            $this->lockCashDesk();
            $session = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! $session->isOpen()) {
                throw ValidationException::withMessages(['session' => 'Cette session est déjà clôturée.']);
            }

            $expected = $this->cashBalances();
            $counted = ['USD' => $this->amount($countedUsd, allowZero: true), 'CDF' => $this->amount($countedCdf, allowZero: true)];
            $diff = ['USD' => bcsub($counted['USD'], $expected['USD'], 2), 'CDF' => bcsub($counted['CDF'], $expected['CDF'], 2)];
            $hasDifference = bccomp($diff['USD'], '0', 2) !== 0 || bccomp($diff['CDF'], '0', 2) !== 0;

            if ($hasDifference && blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'Un écart de caisse est constaté : indiquez-en le motif.']);
            }

            $rate = $this->todayRate();
            foreach ($diff as $cur => $value) {
                if (bccomp($value, '0', 2) === 0) {
                    continue;
                }
                $adjustment = CashMovement::query()->create([
                    'type' => CashMovementType::Adjustment,
                    'direction' => bccomp($value, '0', 2) > 0 ? CashMovement::IN : CashMovement::OUT,
                    'status' => MovementStatus::Validated,
                    'occurred_at' => now(),
                    'cash_session_id' => $session->id,
                    'treasury_account_id' => $this->cashAccount(Currency::from($cur))->id,
                    'currency' => $cur,
                    'amount' => ltrim($value, '-'),
                    'exchange_rate' => $rate,
                    'mode' => PaymentMode::Cash,
                    'label' => 'Écart de caisse à la clôture : '.trim((string) $reason),
                    'created_by' => $by->id,
                ]);
                $this->accounting->movement($adjustment, $by);
            }

            $session->fill([
                'status' => CashSession::CLOSED,
                'closed_at' => now(),
                'closed_by' => $by->id,
                'expected_usd' => $expected['USD'],
                'expected_cdf' => $expected['CDF'],
                'counted_usd' => $counted['USD'],
                'counted_cdf' => $counted['CDF'],
                'difference_usd' => $diff['USD'],
                'difference_cdf' => $diff['CDF'],
                'difference_reason' => $hasDifference ? trim((string) $reason) : null,
            ])->save();

            activity('cash')->causedBy($by)->performedOn($session)
                ->withProperties(['expected' => $expected, 'counted' => $counted, 'difference' => $diff, 'reason' => $reason])
                ->log($hasDifference ? 'Clôture de caisse avec écart' : 'Clôture de caisse sans écart');

            return $session;
        });
    }

    // ------------------------------------------------------------------ Encaissements (CAI-03, CAI-04, FAC-11, FAC-13)

    /**
     * @param  array{client_id: int, invoice_id?: ?int, currency: string, amount: string, mode: string, treasury_account_id?: ?int, reference?: ?string, exchange_rate?: ?string}  $data
     */
    public function recordReceipt(array $data, User $by, bool $sendEmail = true): CashMovement
    {
        $movement = DB::transaction(function () use ($data, $by) {
            $currency = Currency::from($data['currency']);
            $mode = PaymentMode::from($data['mode']);
            $amount = $this->amount((string) $data['amount']);
            $rate = $this->rate($data['exchange_rate'] ?? null);
            $account = $this->resolveAccount($mode, $currency, filled($data['treasury_account_id'] ?? null) ? (int) $data['treasury_account_id'] : null);
            $session = $this->sessionFor($account);
            $client = Client::query()->findOrFail((int) $data['client_id']);

            $invoice = null;
            $invoiceAmount = null;
            if (filled($data['invoice_id'] ?? null)) {
                $invoice = $this->lockOpenInvoice((int) $data['invoice_id'], $client);
                $invoiceAmount = $this->convert($amount, $currency, $invoice->currency, $rate);
                $this->assertWithinBalance($invoice, $invoiceAmount, 'amount');
            }

            $year = now()->year;
            $movement = CashMovement::query()->create([
                'type' => CashMovementType::Receipt,
                'direction' => CashMovement::IN,
                'status' => MovementStatus::Validated,
                'occurred_at' => now(),
                'cash_session_id' => $session?->id,
                'treasury_account_id' => $account->id,
                'currency' => $currency,
                'amount' => $amount,
                'exchange_rate' => $rate,
                'mode' => $mode,
                'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                'receipt_number' => sprintf('RC-%d-%04d', $year, $this->numbering->next("receipt:{$year}")),
                'client_id' => $client->id,
                'invoice_id' => $invoice?->id,
                'invoice_amount' => $invoiceAmount,
                'label' => $invoice ? "Règlement {$invoice->number}" : 'Provision sur honoraires',
                'created_by' => $by->id,
            ]);

            if ($invoice) {
                $this->applyToInvoice($invoice, $invoiceAmount);
            }
            $this->accounting->movement($movement, $by);

            activity('cash')->causedBy($by)->performedOn($movement)
                ->withProperties(['amount' => $amount, 'currency' => $currency->value, 'rate' => $rate, 'invoice' => $invoice?->number])
                ->log("Encaissement {$movement->receipt_number} : ".Money::format($amount, $currency).($invoice ? " sur {$invoice->number}" : ' (provision)'));

            return $movement;
        });

        if ($sendEmail) {
            $this->sendReceipt($movement, $by);
        }

        return $movement;
    }

    /** Disponible sur une provision : montant reçu moins les imputations. */
    public function advanceAvailable(CashMovement $advance): string
    {
        if (! $advance->isAdvance() || $advance->reversal()->exists()) {
            return '0.00';
        }

        return Money::normalize(bcsub((string) $advance->amount, (string) $advance->allocations()->sum('amount'), 2));
    }

    /** FAC-13 : impute (tout ou partie d') une provision sur une facture du même client. */
    public function allocateAdvance(CashMovement $advance, Invoice $invoice, string $amount, User $by, ?string $rate = null): AdvanceAllocation
    {
        return DB::transaction(function () use ($advance, $invoice, $amount, $by, $rate) {
            $advance = CashMovement::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();
            if (! $advance->isAdvance() || $advance->status !== MovementStatus::Validated) {
                throw ValidationException::withMessages(['allocation' => 'Seule une provision reçue sans facture peut être imputée.']);
            }

            $client = Client::query()->findOrFail($advance->client_id);
            $invoice = $this->lockOpenInvoice($invoice->id, $client);
            $amount = $this->amount($amount);

            if (bccomp($amount, $this->advanceAvailable($advance), 2) > 0) {
                throw ValidationException::withMessages(['allocation' => 'Le montant dépasse le solde disponible de la provision ('.Money::format($this->advanceAvailable($advance), $advance->currency).').']);
            }

            $rate = $this->rate($rate);
            $invoiceAmount = $this->convert($amount, $advance->currency, $invoice->currency, $rate);
            $this->assertWithinBalance($invoice, $invoiceAmount, 'allocation');

            $allocation = AdvanceAllocation::query()->create([
                'cash_movement_id' => $advance->id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'invoice_amount' => $invoiceAmount,
                'exchange_rate' => $rate,
                'created_by' => $by->id,
            ]);
            $this->applyToInvoice($invoice, $invoiceAmount);
            $this->accounting->allocation($allocation, $by);

            activity('cash')->causedBy($by)->performedOn($advance)
                ->withProperties(['invoice' => $invoice->number, 'amount' => $amount, 'invoice_amount' => $invoiceAmount])
                ->log("Provision {$advance->receipt_number} imputée sur {$invoice->number} : ".Money::format($invoiceAmount, $invoice->currency));

            return $allocation;
        });
    }

    // ------------------------------------------------------------------ Décaissements (CAI-05)

    /**
     * @param  array{category: string, beneficiary: string, label: string, voucher?: ?string, currency: string, amount: string, mode: string, treasury_account_id?: ?int, client_id?: ?int}  $data
     */
    public function recordDisbursement(array $data, User $by): CashMovement
    {
        return DB::transaction(function () use ($data, $by) {
            $currency = Currency::from($data['currency']);
            $mode = PaymentMode::from($data['mode']);
            $amount = $this->amount((string) $data['amount']);
            $rate = $this->todayRate();
            $account = $this->resolveAccount($mode, $currency, filled($data['treasury_account_id'] ?? null) ? (int) $data['treasury_account_id'] : null);
            $session = $this->sessionFor($account);

            // Au-delà du seuil (500 USD ou l'équivalent CDF), validation par un administrateur.
            $threshold = (string) $this->settings->get('cash.disbursement_threshold_usd');
            $usd = $currency === Currency::USD ? $amount : bcdiv($amount, $rate, 2);
            $needsValidation = bccomp($usd, $threshold, 2) > 0 && ! $by->can(Permission::CashValidate->value);

            if (! $needsValidation) {
                $this->assertSufficient($account, $amount);
            }

            $movement = CashMovement::query()->create([
                'type' => CashMovementType::Disbursement,
                'direction' => CashMovement::OUT,
                'status' => $needsValidation ? MovementStatus::Pending : MovementStatus::Validated,
                'occurred_at' => now(),
                'cash_session_id' => $session?->id,
                'treasury_account_id' => $account->id,
                'currency' => $currency,
                'amount' => $amount,
                'exchange_rate' => $rate,
                'mode' => $mode,
                'category' => DisbursementCategory::from($data['category']),
                'beneficiary' => trim($data['beneficiary']),
                'voucher' => filled($data['voucher'] ?? null) ? trim($data['voucher']) : null,
                'client_id' => filled($data['client_id'] ?? null) ? (int) $data['client_id'] : null,
                'label' => trim($data['label']),
                'validated_by' => $needsValidation ? null : $by->id,
                'validated_at' => $needsValidation ? null : now(),
                'created_by' => $by->id,
            ]);
            $this->accounting->movement($movement, $by); // seulement s'il est validé

            activity('cash')->causedBy($by)->performedOn($movement)
                ->withProperties(['amount' => $amount, 'currency' => $currency->value, 'category' => $movement->category->label()])
                ->log('Décaissement : '.Money::format($amount, $currency).' · '.$movement->label.($needsValidation ? ' (en attente de validation)' : ''));

            return $movement;
        });
    }

    public function validateDisbursement(CashMovement $movement, User $by): CashMovement
    {
        return DB::transaction(function () use ($movement, $by) {
            $movement = $this->lockPending($movement);
            $this->assertSufficient($movement->account, (string) $movement->amount);

            $movement->fill(['status' => MovementStatus::Validated, 'validated_by' => $by->id, 'validated_at' => now()])->save();
            $this->accounting->movement($movement, $by);
            activity('cash')->causedBy($by)->performedOn($movement)->log('Décaissement validé : '.Money::format((string) $movement->amount, $movement->currency).' · '.$movement->label);

            return $movement;
        });
    }

    public function rejectDisbursement(CashMovement $movement, string $reason, User $by): CashMovement
    {
        return DB::transaction(function () use ($movement, $reason, $by) {
            $movement = $this->lockPending($movement);
            if (blank(trim($reason))) {
                throw ValidationException::withMessages(['reason' => 'Indiquez le motif du refus.']);
            }

            $movement->fill(['status' => MovementStatus::Rejected, 'validated_by' => $by->id, 'validated_at' => now(), 'rejection_reason' => trim($reason)])->save();
            activity('cash')->causedBy($by)->performedOn($movement)->log('Décaissement refusé : '.$movement->label.' ('.trim($reason).')');

            return $movement;
        });
    }

    // ------------------------------------------------------------------ Change interne (CAI-06)

    /** Un mouvement : montant cédé dans une devise, montant reçu dans l'autre, au taux saisi. */
    public function exchange(Currency $from, string $amountGiven, string $rate, User $by): CashMovement
    {
        return DB::transaction(function () use ($from, $amountGiven, $rate, $by) {
            $to = $from === Currency::USD ? Currency::CDF : Currency::USD;
            $given = $this->amount($amountGiven);
            $rate = $this->rate($rate);
            $received = $this->convert($given, $from, $to, $rate);
            $fromAccount = $this->cashAccount($from);
            $session = $this->sessionFor($fromAccount);
            $this->assertSufficient($fromAccount, $given);

            $movement = CashMovement::query()->create([
                'type' => CashMovementType::Exchange,
                'direction' => CashMovement::OUT,
                'status' => MovementStatus::Validated,
                'occurred_at' => now(),
                'cash_session_id' => $session?->id,
                'treasury_account_id' => $fromAccount->id,
                'currency' => $from,
                'amount' => $given,
                'exchange_rate' => $rate,
                'counter_account_id' => $this->cashAccount($to)->id,
                'counter_currency' => $to,
                'counter_amount' => $received,
                'mode' => PaymentMode::Cash,
                'label' => "Change {$from->value} vers {$to->value}",
                'validated_by' => $by->id,
                'validated_at' => now(),
                'created_by' => $by->id,
            ]);

            $this->accounting->movement($movement, $by);

            activity('cash')->causedBy($by)->performedOn($movement)
                ->log('Change : '.Money::format($given, $from).' contre '.Money::format($received, $to).' au taux de '.Money::formatRate($rate));

            return $movement;
        });
    }

    // ------------------------------------------------------------------ Contre-passation (CAI-08)

    /**
     * Annule un mouvement par l'opération inverse : même compte, sens opposé, même taux.
     * Un règlement contre-passé diminue d'autant le montant payé de la facture.
     */
    public function reverse(CashMovement $movement, string $reason, User $by): CashMovement
    {
        return DB::transaction(function () use ($movement, $reason, $by) {
            $original = CashMovement::query()->whereKey($movement->id)->lockForUpdate()->firstOrFail();

            if (blank(trim($reason))) {
                throw ValidationException::withMessages(['reason' => 'Indiquez le motif de la contre-passation.']);
            }
            if (! in_array($original->type, [CashMovementType::Receipt, CashMovementType::Disbursement, CashMovementType::Exchange], true)
                || $original->status !== MovementStatus::Validated) {
                throw ValidationException::withMessages(['reason' => 'Seul un encaissement, un décaissement ou un change validé peut être contre-passé.']);
            }
            if ($original->reversal()->exists()) {
                throw ValidationException::withMessages(['reason' => 'Ce mouvement a déjà été contre-passé.']);
            }
            if ($original->isAdvance() && $original->allocations()->exists()) {
                throw ValidationException::withMessages(['reason' => 'Cette provision est déjà imputée sur une facture : elle ne peut plus être contre-passée.']);
            }

            $isExchange = $original->type === CashMovementType::Exchange;
            $account = $isExchange ? $original->counterAccount : $original->account;
            $session = $this->sessionFor($account) ?? ($isExchange ? $this->sessionFor($original->account) : null);
            $amountOut = $isExchange ? (string) $original->counter_amount : (string) $original->amount;
            if ($original->direction === CashMovement::IN || $isExchange) {
                $this->assertSufficient($account, $amountOut);
            }

            $reversal = CashMovement::query()->create([
                'type' => CashMovementType::Reversal,
                'direction' => $isExchange ? CashMovement::OUT : ($original->direction === CashMovement::IN ? CashMovement::OUT : CashMovement::IN),
                'status' => MovementStatus::Validated,
                'occurred_at' => now(),
                'cash_session_id' => $session?->id,
                'treasury_account_id' => $account->id,
                'currency' => $isExchange ? $original->counter_currency : $original->currency,
                'amount' => $amountOut,
                'exchange_rate' => (string) $original->exchange_rate,
                'counter_account_id' => $isExchange ? $original->treasury_account_id : null,
                'counter_currency' => $isExchange ? $original->currency : null,
                'counter_amount' => $isExchange ? (string) $original->amount : null,
                'mode' => $original->mode,
                'client_id' => $original->client_id,
                'invoice_id' => $original->invoice_id,
                'invoice_amount' => $original->invoice_amount,
                'category' => $original->category,
                'beneficiary' => $original->beneficiary,
                'label' => 'Contre-passation de « '.$original->label.' » : '.trim($reason),
                'reverses_id' => $original->id,
                'validated_by' => $by->id,
                'validated_at' => now(),
                'created_by' => $by->id,
            ]);
            $this->accounting->movement($reversal, $by); // contre-passation de l'écriture d'origine

            if ($original->invoice_id) {
                $invoice = Invoice::query()->whereKey($original->invoice_id)->lockForUpdate()->firstOrFail();
                $this->applyToInvoice($invoice, '-'.$original->invoice_amount);
            }

            activity('cash')->causedBy($by)->performedOn($original)
                ->withProperties(['reversal_id' => $reversal->id, 'reason' => trim($reason)])
                ->log('Contre-passation : '.$original->label);

            return $reversal;
        });
    }

    // ------------------------------------------------------------------ Reçu (CAI-03, NOT-05)

    public function sendReceipt(CashMovement $movement, ?User $by): EmailLog
    {
        $movement->loadMissing('client');

        return $this->emails->send(EmailTemplate::RECEIPT, $movement->client, [
            'client' => $movement->client->name,
            'numero' => $movement->receipt_number,
            'montant' => Money::format((string) $movement->amount, $movement->currency),
            'mode' => $movement->mode->label(),
            'devise' => $movement->currency->value,
        ], $movement, [['type' => 'receipt_pdf', 'name' => $movement->receipt_number.'.pdf']], $by);
    }

    // ------------------------------------------------------------------ Outils

    /** Montant converti d'une devise à l'autre au taux (CDF pour 1 USD), arrondi au centime. */
    public function convert(string $amount, Currency $from, Currency $to, string $rate): string
    {
        if ($from === $to) {
            return Money::normalize($amount);
        }

        return $from === Currency::CDF
            ? Money::round(bcdiv($amount, $rate, 6))
            : Money::round(bcmul($amount, $rate, 6));
    }

    private function applyToInvoice(Invoice $invoice, string $delta): void
    {
        $paid = Money::normalize(bcadd((string) $invoice->paid_amount, $delta, 2));
        $status = match (true) {
            bccomp($paid, (string) $invoice->total, 2) >= 0 => InvoiceStatus::Paid,
            bccomp($paid, '0', 2) > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Issued,
        };

        $invoice->fill(['paid_amount' => $paid, 'status' => $status])->save();
    }

    private function lockOpenInvoice(int $invoiceId, Client $client): Invoice
    {
        $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

        if ($invoice->client_id !== $client->id) {
            throw ValidationException::withMessages(['invoice_id' => 'Cette facture n\'appartient pas au client choisi.']);
        }
        if (! $invoice->status->isOpen()) {
            throw ValidationException::withMessages(['invoice_id' => 'Cette facture n\'attend pas de paiement ('.$invoice->status->label().').']);
        }

        return $invoice;
    }

    private function assertWithinBalance(Invoice $invoice, string $invoiceAmount, string $field): void
    {
        if (bccomp($invoiceAmount, $invoice->balanceDue(), 2) > 0) {
            throw ValidationException::withMessages([
                $field => 'Le montant ('.Money::format($invoiceAmount, $invoice->currency).') dépasse le reste dû ('
                    .Money::format($invoice->balanceDue(), $invoice->currency).'). Encaissez l\'excédent comme provision sans facture.',
            ]);
        }
    }

    private function assertSufficient(TreasuryAccount $account, string $amount): void
    {
        $balance = $this->balance($account);
        if ($account->isCash() && bccomp($amount, $balance, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'Solde insuffisant : '.$account->name.' contient '.Money::format($balance, $account->currency).'.',
            ]);
        }
    }

    private function resolveAccount(PaymentMode $mode, Currency $currency, ?int $accountId): TreasuryAccount
    {
        $query = TreasuryAccount::query()->where('is_active', true)->where('type', $mode->treasuryType()->value)->where('currency', $currency->value);
        $account = $accountId ? (clone $query)->whereKey($accountId)->first() : $query->orderBy('id')->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'treasury_account_id' => 'Aucun compte « '.$mode->treasuryType()->label().' » actif en '.$currency->value.' pour le mode '.$mode->label().'.',
            ]);
        }

        return $account;
    }

    /** Toute opération sur la caisse physique exige une session ouverte. */
    private function sessionFor(TreasuryAccount $account): ?CashSession
    {
        if (! $account->isCash()) {
            return $this->currentSession();
        }

        $session = $this->currentSession();
        if ($session === null) {
            throw ValidationException::withMessages(['session' => 'La caisse est fermée : ouvrez une session avant toute opération en espèces.']);
        }

        return $session;
    }

    private function lockPending(CashMovement $movement): CashMovement
    {
        $movement = CashMovement::query()->with('account')->whereKey($movement->id)->lockForUpdate()->firstOrFail();
        if ($movement->status !== MovementStatus::Pending) {
            throw ValidationException::withMessages(['movement' => 'Ce décaissement n\'est plus en attente.']);
        }

        return $movement;
    }

    /** Verrou sur les comptes de caisse : sérialise ouverture, clôture et sorties d'espèces. */
    private function lockCashDesk(): void
    {
        TreasuryAccount::query()->where('type', TreasuryType::Cash->value)->lockForUpdate()->get();
    }

    private function todayRate(): string
    {
        $rate = $this->rates->rateFor();
        if ($rate === null) {
            throw ValidationException::withMessages(['exchange_rate' => 'Aucun taux de change n\'est enregistré : saisissez le taux du jour.']);
        }

        return (string) $rate->rate;
    }

    private function rate(?string $rate): string
    {
        if (blank($rate)) {
            return $this->todayRate();
        }

        $rate = Money::normalize($rate, 4);
        if (bccomp($rate, '0', 4) <= 0) {
            throw ValidationException::withMessages(['exchange_rate' => 'Le taux doit être strictement positif.']);
        }

        return $rate;
    }

    private function amount(string $value, bool $allowZero = false): string
    {
        try {
            $amount = Money::normalize($value);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => 'Montant invalide.']);
        }

        if (bccomp($amount, '0', 2) < 0 || (! $allowZero && bccomp($amount, '0', 2) === 0)) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être supérieur à zéro.']);
        }

        return $amount;
    }
}
