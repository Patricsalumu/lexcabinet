{{-- Tableau unique pour l'export Excel : titre, période, puis l'état. --}}
<table>
    <tr><td><b>{{ $firm ?? app(App\Services\SettingsService::class)->get('firm.name') }}</b></td></tr>
    <tr><td><b>{{ $title }}</b></td></tr>
    <tr><td>Du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }} · montants en USD</td></tr>
    <tr><td></td></tr>
</table>
@include('accounting.partials.'.$report)
