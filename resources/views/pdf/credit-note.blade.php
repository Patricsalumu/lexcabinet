@php
    use App\Support\Money;
    $m = fn ($v) => Money::format((string) $v, $credit->currency);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $credit->number }}</title>
    @include('pdf.partials.style')
</head>
<body>
    @include('pdf.partials.header', ['docTitle' => 'AVOIR', 'docNumber' => $credit->number, 'docDates' => 'Date : '.$credit->issue_date->format('d/m/Y')])

    <p>
        <span class="small">Client</span><br><b>{{ $credit->client->name }}</b><br>
        <span class="small">{{ $credit->client->address }}</span>
    </p>
    <p>
        Avoir annulant la facture <b class="mono">{{ $credit->invoice->number }}</b> du {{ $credit->invoice->issue_date->format('d/m/Y') }}
        @if ($credit->invoice->legalCase), dossier {{ $credit->invoice->legalCase->reference }} · {{ $credit->invoice->legalCase->title }}@endif.
    </p>
    <p><span class="small">Motif</span><br>{{ $credit->reason }}</p>

    <table class="totals">
        <tr><td>Honoraires nets</td><td class="num">{{ $m($credit->fees_amount) }}</td></tr>
        @if (bccomp((string) $credit->other_amount, '0', 2) > 0)<tr><td>Débours et frais</td><td class="num">{{ $m($credit->other_amount) }}</td></tr>@endif
        @if (bccomp((string) $credit->vat_amount, '0', 2) > 0)<tr><td>TVA</td><td class="num">{{ $m($credit->vat_amount) }}</td></tr>@endif
        <tr class="grand"><td>Total de l'avoir</td><td class="num">{{ $m($credit->total) }}</td></tr>
    </table>

    <p class="small" style="margin-top:18px">Taux de la facture d'origine : {{ Money::formatRate((string) $credit->exchange_rate) }} FC pour 1 $.</p>
    <div class="foot">{{ $firm['firm.name'] }} · {{ $firm['firm.address'] }}</div>
</body>
</html>
