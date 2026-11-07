<?php

namespace App\Livewire\Cash;

use App\Enums\Currency;
use App\Enums\EmailStatus;
use App\Enums\PaymentMode;
use App\Enums\Permission;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\TreasuryAccount;
use App\Services\CashService;
use App\Services\ExchangeRateService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Encaissement (CAI-03, CAI-04) : sur une facture, ou comme provision sans facture (FAC-13).
 */
#[Layout('layouts.app')]
#[Title('Encaisser')]
class ReceiptForm extends Component
{
    public string $client_id = '';

    public string $invoice_id = '';

    public string $currency = 'USD';

    public string $amount = '';

    public string $mode = 'cash';

    public string $treasury_account_id = '';

    public string $reference = '';

    public string $exchange_rate = '';

    public function mount(ExchangeRateService $rates): void
    {
        $this->authorize(Permission::CashOperate->value);
        $this->exchange_rate = $rates->rateFor() ? rtrim(rtrim((string) $rates->rateFor()->rate, '0'), '.') : '';

        if ($invoiceId = request()->query('invoice')) {
            $invoice = Invoice::query()->find((int) $invoiceId);
            if ($invoice && $invoice->status->isOpen()) {
                $this->client_id = (string) $invoice->client_id;
                $this->invoice_id = (string) $invoice->id;
                $this->currency = $invoice->currency->value;
                $this->amount = $invoice->balanceDue();
            }
        }
    }

    public function updatedClientId(): void
    {
        $this->invoice_id = '';
    }

    public function updatedInvoiceId(): void
    {
        if ($invoice = $this->selectedInvoice) {
            $this->currency = $invoice->currency->value;
            $this->amount = $invoice->balanceDue();
        }
    }

    #[Computed]
    public function openInvoices()
    {
        return $this->client_id === '' ? collect() : Invoice::query()
            ->where('client_id', (int) $this->client_id)
            ->whereIn('status', ['issued', 'partially_paid'])
            ->orderBy('due_date')->get();
    }

    #[Computed]
    public function selectedInvoice(): ?Invoice
    {
        return $this->invoice_id !== '' ? Invoice::query()->find((int) $this->invoice_id) : null;
    }

    /** Aperçu de l'imputation, conversion comprise. */
    #[Computed]
    public function imputation(): ?string
    {
        $invoice = $this->selectedInvoice;
        if (! $invoice || ! is_numeric($this->amount) || ! is_numeric($this->exchange_rate) || (float) $this->exchange_rate <= 0) {
            return null;
        }

        $applied = app(CashService::class)->convert(Money::normalize((string) $this->amount), Currency::from($this->currency), $invoice->currency, Money::normalize($this->exchange_rate, 4));
        $rest = bcsub($invoice->balanceDue(), $applied, 2);

        return 'Imputé sur '.$invoice->number.' : '.Money::format($applied, $invoice->currency)
            .($this->currency !== $invoice->currency->value ? ' (conversion au taux de '.Money::formatRate($this->exchange_rate).' FC pour 1 $)' : '')
            .'. Reste après paiement : '.Money::format(bccomp($rest, '0', 2) < 0 ? '0' : $rest, $invoice->currency)
            .(bccomp($rest, '0', 2) < 0 ? ' — le montant dépasse le reste dû.' : '.');
    }

    #[Computed]
    public function accounts()
    {
        $type = PaymentMode::from($this->mode)->treasuryType();

        return TreasuryAccount::query()->where('is_active', true)->where('type', $type->value)->where('currency', $this->currency)->orderBy('id')->get();
    }

    public function save(CashService $cash): void
    {
        $this->authorize(Permission::CashOperate->value);

        $data = $this->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')],
            'currency' => ['required', Rule::enum(Currency::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'mode' => ['required', Rule::enum(PaymentMode::class)],
            'treasury_account_id' => ['nullable', 'integer'],
            'reference' => [$this->mode === 'cash' ? 'nullable' : 'required', 'string', 'max:100'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
        ], ['reference.required' => 'Indiquez la référence du virement, du chèque ou de la transaction.'], [
            'client_id' => 'client', 'invoice_id' => 'facture', 'amount' => 'montant', 'exchange_rate' => 'taux',
        ]);

        $movement = $cash->recordReceipt($data, auth()->user());

        $log = EmailLog::query()->where('related_type', $movement->getMorphClass())->where('related_id', $movement->id)->latest('id')->first();
        session()->flash('status', "Reçu {$movement->receipt_number} émis."
            .($log && $log->status === EmailStatus::NotSent ? ' E-mail non envoyé : '.$log->reason : ' Copie envoyée au client par e-mail.'));
        session()->flash('receipt_id', $movement->id);

        $this->redirectRoute('cash.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.cash.receipt', [
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'modes' => PaymentMode::cases(),
        ]);
    }
}
