@php
    use App\Support\Money;
    $m = $movement;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $m->receipt_number }}</title>
    @include('pdf.partials.style')
    <style>@page { margin: 14mm; }</style>
</head>
<body>
    @include('pdf.partials.header', [
        'docTitle' => 'REÇU DE PAIEMENT',
        'docNumber' => $m->receipt_number,
        'docDates' => $m->occurred_at->format('d/m/Y').' à '.$m->occurred_at->format('H\hi'),
    ])

    @if ($m->reversal)
        <p><span class="stamp">Annulé par contre-passation le {{ $m->reversal->occurred_at->format('d/m/Y') }}</span></p>
    @endif

    <p style="font-size:11pt">
        Reçu de <b>{{ $m->client->name }}</b> la somme de <b class="mono">{{ Money::format((string) $m->amount, $m->currency) }}</b>
        par {{ mb_strtolower($m->mode->label()) }}@if ($m->reference) (réf. {{ $m->reference }})@endif.
    </p>

    @if ($m->invoice)
        <p>
            Imputé sur la facture <b class="mono">{{ $m->invoice->number }}</b> :
            {{ Money::format((string) $m->invoice_amount, $m->invoice->currency) }}
            @if ($m->currency !== $m->invoice->currency) au taux de {{ Money::formatRate((string) $m->exchange_rate) }} FC pour 1 $@endif.
            Reste dû : {{ Money::format($m->invoice->balanceDue(), $m->invoice->currency) }}.
        </p>
    @else
        <p>Enregistré comme provision sur honoraires, à imputer sur une prochaine facture.</p>
    @endif

    <p class="small" style="margin-top:20px">Encaissé par {{ $m->creator?->name }}. Taux du jour : {{ Money::formatRate((string) $m->exchange_rate) }} FC pour 1 $.</p>
    <div class="foot">{{ $firm['firm.name'] }} · {{ $firm['firm.address'] }}</div>
</body>
</html>
