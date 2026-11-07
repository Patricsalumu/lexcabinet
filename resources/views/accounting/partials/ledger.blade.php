<table class="lx-table lines">
    <thead>
        <tr><th>Date</th><th>Jnl</th><th>N°</th><th>Pièce</th><th>Libellé</th><th class="num text-right">Débit $</th><th class="num text-right">Crédit $</th><th class="num text-right">Solde $</th></tr>
    </thead>
    <tbody>
        @forelse ($ledger as $acc)
            <tr class="bg-slate-50 font-semibold" style="background:#f3f4f7">
                <td colspan="7">{{ $acc['account']->code }} · {{ $acc['account']->label }} — solde d'ouverture</td>
                <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $acc['opening'], 'blankZero' => false])</td>
            </tr>
            @foreach ($acc['lines'] as $l)
                <tr>
                    <td class="whitespace-nowrap font-mono text-xs">{{ $l['date']->format('d/m/Y') }}</td>
                    <td class="font-mono text-xs">{{ $l['journal'] }}</td>
                    <td class="whitespace-nowrap font-mono text-xs">{{ $l['number'] }}</td>
                    <td class="whitespace-nowrap font-mono text-xs">{{ $l['reference'] }}</td>
                    <td>{{ $l['label'] }}</td>
                    <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $l['debit']])</td>
                    <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $l['credit']])</td>
                    <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $l['balance'], 'blankZero' => false])</td>
                </tr>
            @endforeach
            <tr class="font-semibold">
                <td colspan="5">Total {{ $acc['account']->code }}</td>
                <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $acc['debit'], 'blankZero' => false])</td>
                <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $acc['credit'], 'blankZero' => false])</td>
                <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $acc['closing'], 'blankZero' => false])</td>
            </tr>
        @empty
            <tr><td colspan="8">Aucune écriture sur la période.</td></tr>
        @endforelse
    </tbody>
</table>
