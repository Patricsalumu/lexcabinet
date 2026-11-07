@php $n = $numeric ?? false; $td = 0; $tc = '0'; $tdv = '0'; @endphp
<table class="lx-table lines">
    <thead>
        <tr><th>Date</th><th>Jnl</th><th>N°</th><th>Pièce</th><th>Compte</th><th>Libellé</th><th class="num text-right">Débit $</th><th class="num text-right">Crédit $</th></tr>
    </thead>
    <tbody>
        @foreach ($entries as $entry)
            @foreach ($entry->lines as $k => $line)
                @php $tdv = bcadd($tdv, (string) $line->debit, 2); $tc = bcadd($tc, (string) $line->credit, 2); @endphp
                <tr @if ($k === 0) style="border-top:2px solid #d9dce6" @endif>
                    <td class="whitespace-nowrap font-mono text-xs">{{ $k === 0 ? $entry->entry_date->format('d/m/Y') : '' }}</td>
                    <td class="font-mono text-xs">{{ $k === 0 ? $entry->journal->value : '' }}</td>
                    <td class="whitespace-nowrap font-mono text-xs">{{ $k === 0 ? $entry->number : '' }}</td>
                    <td class="whitespace-nowrap font-mono text-xs">{{ $k === 0 ? $entry->reference : '' }}</td>
                    <td class="font-mono">{{ $line->account->code }}</td>
                    <td>
                        @if ($k === 0){{ $entry->label }}@if ($entry->is_manual) (manuelle)@endif @if ($entry->reverses_id) (contre-passation)@endif<br>@endif
                        <span class="text-xs text-slate-500">{{ $line->account->label }}@if ($line->original_currency->value === 'CDF') · {{ App\Support\Money::format((string) $line->original_amount, 'CDF') }} à {{ App\Support\Money::formatRate((string) $line->exchange_rate) }}@endif</span>
                    </td>
                    <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $line->debit])</td>
                    <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $line->credit])</td>
                </tr>
            @endforeach
        @endforeach
    </tbody>
    <tfoot>
        <tr class="font-semibold"><td colspan="6">Totaux</td>
            <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $tdv, 'blankZero' => false])</td>
            <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $tc, 'blankZero' => false])</td></tr>
    </tfoot>
</table>
