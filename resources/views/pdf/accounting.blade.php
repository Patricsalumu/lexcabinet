<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('pdf.partials.style')
    <style>
        @page { margin: 16mm 12mm; }
        body { font-size: 8.5pt; }
        .lines td, .lines th { padding: 3px 4px; }
        .text-xs, .text-slate-500 { font-size: 7.5pt; color: #585f77; }
        tfoot td, .font-semibold td, tr.font-semibold td { font-weight: bold; }
    </style>
</head>
<body>
    @include('pdf.partials.header', [
        'docTitle' => mb_strtoupper($title),
        'docNumber' => 'Montants en USD',
        'docDates' => 'Du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y').'<br>Édité le '.now()->format('d/m/Y à H\hi'),
    ])
    @include('accounting.partials.'.$report)
    <div class="foot">{{ $firm['firm.name'] }} · {{ $title }} · plan inspiré du SYSCOHADA révisé</div>
</body>
</html>
