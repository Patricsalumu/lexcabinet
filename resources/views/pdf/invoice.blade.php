@php
    use App\Enums\InvoiceStatus;
    use App\Support\Money;
    $cur = $invoice->currency;
    $m = fn ($v) => Money::format((string) $v, $cur);
    $title = match (true) {
        $invoice->status === InvoiceStatus::Draft => 'BROUILLON',
        $invoice->status === InvoiceStatus::Cancelled => 'FACTURE ANNULÉE',
        default => 'NOTE D\'HONORAIRES',
    };
    $dates = $invoice->issue_date
        ? 'Date : '.$invoice->issue_date->format('d/m/Y').'<br>Échéance : '.$invoice->due_date->format('d/m/Y')
        : 'Non émise';
    $feesNet = bcsub((string) $invoice->fees_total, (string) $invoice->discount_amount, 2);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->displayNumber() }}</title>
    @include('pdf.partials.style')
</head>
<body>
    @include('pdf.partials.header', ['docTitle' => $title, 'docNumber' => $invoice->number ?? '—', 'docDates' => $dates])

    <table style="width:100%;margin-bottom:8px">
        <tr>
            <td style="width:60%">
                <span class="small">Facturé à</span><br>
                <b>{{ $invoice->client->name }}</b><br>
                <span class="small">
                    {{ $invoice->client->address }}
                    @if ($invoice->client->rccm)<br>RCCM {{ $invoice->client->rccm }}@endif
                    @if ($invoice->client->id_nat) · ID. Nat. {{ $invoice->client->id_nat }}@endif
                    @if ($invoice->client->phone)<br>{{ $invoice->client->phone }}@endif
                </span>
            </td>
            <td style="width:40%" class="small">
                @if ($invoice->legalCase)
                    Dossier {{ $invoice->legalCase->reference }}<br>{{ $invoice->legalCase->title }}
                    @if ($invoice->legalCase->docket_number)<br>{{ $invoice->legalCase->docket_number }}@endif
                @else
                    Consultation sans dossier
                @endif
                @if ($invoice->period_start)<br>Période du {{ $invoice->period_start->format('d/m/Y') }} au {{ $invoice->period_end->format('d/m/Y') }}@endif
            </td>
        </tr>
    </table>

    @if ($invoice->status === InvoiceStatus::Cancelled && $invoice->creditNote)
        <p><span class="stamp">Annulée par l'avoir {{ $invoice->creditNote->number }} du {{ $invoice->creditNote->issue_date->format('d/m/Y') }}</span></p>
    @endif

    <table class="lines">
        <thead>
            <tr><th>Désignation</th><th>Type</th><th class="num">Qté</th><th class="num">Prix unit.</th><th class="num">Montant</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->label }}</td>
                    <td>{{ $line->type->label() }}{{ $line->taxable ? '' : ' (hors taxe)' }}</td>
                    <td class="num">{{ $line->quantityLabel() }}</td>
                    <td class="num">{{ $m($line->unit_price) }}</td>
                    <td class="num">{{ $m($line->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Honoraires</td><td class="num">{{ $m($invoice->fees_total) }}</td></tr>
        @if (bccomp((string) $invoice->discount_amount, '0', 2) > 0)
            <tr><td>Réduction de {{ rtrim(rtrim(str_replace('.', ',', (string) $invoice->discount_percent), '0'), ',') }} % sur les honoraires</td><td class="num">-{{ $m($invoice->discount_amount) }}</td></tr>
            <tr><td>Honoraires nets</td><td class="num">{{ $m($feesNet) }}</td></tr>
        @endif
        @if (bccomp((string) $invoice->other_total, '0', 2) > 0)
            <tr><td>Débours et frais</td><td class="num">{{ $m($invoice->other_total) }}</td></tr>
        @endif
        @if ($invoice->vat_enabled)
            <tr><td>TVA {{ rtrim(rtrim(str_replace('.', ',', (string) $invoice->vat_rate), '0'), ',') }} % sur {{ $m($invoice->taxable_base) }}</td><td class="num">{{ $m($invoice->vat_amount) }}</td></tr>
        @endif
        <tr class="grand"><td>Total à payer</td><td class="num">{{ $m($invoice->total) }}</td></tr>
        @if ($invoice->status->isLocked() && $invoice->status !== InvoiceStatus::Cancelled)
            <tr><td>Déjà payé</td><td class="num">{{ $m($invoice->paid_amount) }}</td></tr>
            <tr><td><b>Reste à payer</b></td><td class="num"><b>{{ $m($invoice->balanceDue()) }}</b></td></tr>
        @endif
    </table>

    @if ($invoice->exchange_rate)
        <p class="small" style="margin-top:18px">
            @if ($cur === App\Enums\Currency::USD)
                Contre-valeur indicative : {{ Money::format(bcmul((string) $invoice->total, (string) $invoice->exchange_rate, 2), 'CDF') }}
            @else
                Contre-valeur indicative : {{ Money::format(bcdiv((string) $invoice->total, (string) $invoice->exchange_rate, 6), 'USD') }}
            @endif
            au taux de {{ Money::formatRate((string) $invoice->exchange_rate) }} FC pour 1 $.
        </p>
    @endif
    @php $methods = app(App\Services\EmailSignature::class)->paymentLines(); @endphp
    @if ($methods)
        <p class="small"><b>Moyens de paiement</b> (rappelez le numéro de la facture) :<br>{!! implode('<br>', array_map('e', $methods)) !!}</p>
    @else
        <p class="small">Paiement : espèces au cabinet, virement ({{ $firm['firm.bank_details'] }}) ou mobile money, en rappelant le numéro de la facture.</p>
    @endif
    @if ($invoice->notes)<p class="small">{{ $invoice->notes }}</p>@endif

    <div class="foot">{{ $firm['firm.name'] }} · {{ $firm['firm.address'] }}</div>
</body>
</html>
