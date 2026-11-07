<?php

namespace Database\Seeders;

use App\Enums\CashMovementType;
use App\Enums\Currency;
use App\Enums\DisbursementCategory;
use App\Enums\InvoiceStatus;
use App\Enums\MovementStatus;
use App\Enums\PaymentMode;
use App\Enums\TreasuryType;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\CashService;
use App\Services\NumberingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Caisse de démonstration du prototype : comptes, soldes d'ouverture il y a 30 jours,
 * opérations des derniers jours et session ouverte ce matin par le caissier.
 */
class CashSeeder extends Seeder
{
    public function run(CashService $cash, NumberingService $numbering): void
    {
        $chris = User::query()->where('email', 'chris.mbuyi@mukendi-avocats.example')->firstOrFail();
        $grace = User::query()->where('email', 'grace.mukendi@mukendi-avocats.example')->firstOrFail();
        $at = fn (int $offset, string $time) => Carbon::today()->addDays($offset)->setTimeFromTimeString($time);
        $client = fn (string $name) => Client::query()->where('name', $name)->firstOrFail();
        $invoice = fn (int $n) => Invoice::query()->where('number', sprintf('FAC-%d-%05d', now()->year, $n))->firstOrFail();

        $usd = TreasuryAccount::query()->create(['name' => 'Caisse USD', 'type' => TreasuryType::Cash, 'currency' => Currency::USD, 'ledger_code' => '5711']);
        $cdf = TreasuryAccount::query()->create(['name' => 'Caisse CDF', 'type' => TreasuryType::Cash, 'currency' => Currency::CDF, 'ledger_code' => '5712']);
        $bank = TreasuryAccount::query()->create(['name' => 'Rawbank, compte USD', 'type' => TreasuryType::Bank, 'currency' => Currency::USD, 'ledger_code' => '521']);
        $momo = TreasuryAccount::query()->create(['name' => 'Mobile money (M-Pesa)', 'type' => TreasuryType::MobileMoney, 'currency' => Currency::USD, 'ledger_code' => '5216']);

        $move = fn (array $data) => CashMovement::query()->create($data + [
            'status' => MovementStatus::Validated,
            'validated_by' => $chris->id,
            'validated_at' => $data['occurred_at'],
            'created_by' => $chris->id,
        ]);
        $pay = function (Invoice $inv, string $amount) {
            $paid = bcadd((string) $inv->paid_amount, $amount, 2);
            $inv->fill(['paid_amount' => $paid, 'status' => bccomp($paid, (string) $inv->total, 2) >= 0 ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid])->save();
        };

        // Soldes d'ouverture (reprise de la caisse existante).
        $move(['type' => CashMovementType::OpeningBalance, 'direction' => 'in', 'occurred_at' => $at(-30, '08:00'), 'treasury_account_id' => $usd->id, 'currency' => 'USD', 'amount' => '1200.00', 'exchange_rate' => '2780', 'mode' => PaymentMode::Cash, 'label' => 'Solde d\'ouverture de la caisse USD']);
        $move(['type' => CashMovementType::OpeningBalance, 'direction' => 'in', 'occurred_at' => $at(-30, '08:00'), 'treasury_account_id' => $cdf->id, 'currency' => 'CDF', 'amount' => '2500000.00', 'exchange_rate' => '2780', 'mode' => PaymentMode::Cash, 'label' => 'Solde d\'ouverture de la caisse CDF']);

        $year = now()->year;
        $receipt = fn (int $n) => sprintf('RC-%d-%04d', $year, $n);

        // Opérations des jours précédents.
        $i1 = $invoice(115);
        $move(['type' => CashMovementType::Receipt, 'direction' => 'in', 'occurred_at' => $at(-6, '10:12'), 'treasury_account_id' => $usd->id, 'currency' => 'USD', 'amount' => '1500.00', 'exchange_rate' => '2790', 'mode' => PaymentMode::Cash, 'receipt_number' => $receipt(306), 'client_id' => $i1->client_id, 'invoice_id' => $i1->id, 'invoice_amount' => '1500.00', 'label' => 'Règlement '.$i1->number]);
        $pay($i1, '1500.00');

        $i2 = $invoice(116);
        $move(['type' => CashMovementType::Receipt, 'direction' => 'in', 'occurred_at' => $at(-5, '14:40'), 'treasury_account_id' => $bank->id, 'currency' => 'USD', 'amount' => '800.00', 'exchange_rate' => '2790', 'mode' => PaymentMode::Transfer, 'reference' => 'Rawbank VIR-88412', 'receipt_number' => $receipt(307), 'client_id' => $i2->client_id, 'invoice_id' => $i2->id, 'invoice_amount' => '800.00', 'label' => 'Règlement '.$i2->number]);
        $pay($i2, '800.00');

        $move(['type' => CashMovementType::Disbursement, 'direction' => 'out', 'occurred_at' => $at(-3, '09:05'), 'treasury_account_id' => $usd->id, 'currency' => 'USD', 'amount' => '450.00', 'exchange_rate' => '2790', 'mode' => PaymentMode::Cash, 'category' => DisbursementCategory::Rent, 'beneficiary' => 'SCI Gombe Plaza', 'voucher' => 'Quittance 11/2026', 'label' => 'Loyer du cabinet, quote-part du mois']);

        $i4 = $invoice(118);
        $move(['type' => CashMovementType::Receipt, 'direction' => 'in', 'occurred_at' => $at(-2, '11:20'), 'treasury_account_id' => $cdf->id, 'currency' => 'CDF', 'amount' => '700000.00', 'exchange_rate' => '2795', 'mode' => PaymentMode::Cash, 'receipt_number' => $receipt(308), 'client_id' => $i4->client_id, 'invoice_id' => $i4->id, 'invoice_amount' => '700000.00', 'label' => 'Règlement '.$i4->number]);
        $pay($i4, '700000.00');

        $move(['type' => CashMovementType::Disbursement, 'direction' => 'out', 'occurred_at' => $at(-1, '08:50'), 'treasury_account_id' => $cdf->id, 'currency' => 'CDF', 'amount' => '150000.00', 'exchange_rate' => '2800', 'mode' => PaymentMode::Cash, 'category' => DisbursementCategory::CourtFees, 'beneficiary' => 'Greffe du tribunal de commerce', 'voucher' => 'Bordereau 4471', 'label' => 'Consignation des frais, RCE 1123']);

        $move(['type' => CashMovementType::Exchange, 'direction' => 'out', 'occurred_at' => $at(-1, '16:30'), 'treasury_account_id' => $usd->id, 'currency' => 'USD', 'amount' => '200.00', 'exchange_rate' => '2800', 'counter_account_id' => $cdf->id, 'counter_currency' => 'CDF', 'counter_amount' => '560000.00', 'mode' => PaymentMode::Cash, 'label' => 'Change USD vers CDF pour menues dépenses']);

        // Session ouverte ce matin, fonds comptés conformes au solde théorique.
        $book = $cash->cashBalances();
        $session = CashSession::query()->create([
            'status' => CashSession::OPEN, 'opened_at' => $at(0, '08:05'), 'opened_by' => $chris->id,
            'opening_usd' => $book['USD'], 'opening_cdf' => $book['CDF'], 'book_opening_usd' => $book['USD'], 'book_opening_cdf' => $book['CDF'],
        ]);

        $move(['type' => CashMovementType::Receipt, 'direction' => 'in', 'occurred_at' => $at(0, '09:40'), 'cash_session_id' => $session->id, 'treasury_account_id' => $usd->id, 'currency' => 'USD', 'amount' => '300.00', 'exchange_rate' => '2800', 'mode' => PaymentMode::Cash, 'receipt_number' => $receipt(309), 'client_id' => $i1->client_id, 'invoice_id' => $i1->id, 'invoice_amount' => '300.00', 'label' => 'Règlement '.$i1->number]);
        $pay($i1->fresh(), '300.00');

        // Décaissement au-delà du seuil, en attente de l'administrateur.
        CashMovement::query()->create(['type' => CashMovementType::Disbursement, 'direction' => 'out', 'status' => MovementStatus::Pending, 'occurred_at' => $at(0, '10:15'), 'cash_session_id' => $session->id, 'treasury_account_id' => $usd->id, 'currency' => 'USD', 'amount' => '820.00', 'exchange_rate' => '2800', 'mode' => PaymentMode::Cash, 'category' => DisbursementCategory::Equipment, 'beneficiary' => 'Kin Informatique', 'voucher' => 'Facture KI-2291', 'label' => 'Ordinateur portable pour le secrétariat', 'created_by' => $chris->id]);

        // Provision sans facture reçue par mobile money.
        $move(['type' => CashMovementType::Receipt, 'direction' => 'in', 'occurred_at' => $at(0, '10:50'), 'cash_session_id' => $session->id, 'treasury_account_id' => $momo->id, 'currency' => 'USD', 'amount' => '100.00', 'exchange_rate' => '2800', 'mode' => PaymentMode::MobileMoney, 'reference' => 'M-Pesa 7K3P29XQ', 'receipt_number' => $receipt(310), 'client_id' => $client('Rachel Mwamba')->id, 'label' => 'Provision consultation Rachel Mwamba']);

        // Le prochain reçu sera RC-AAAA-0311.
        $numbering->set("receipt:{$year}", 310);
    }
}
