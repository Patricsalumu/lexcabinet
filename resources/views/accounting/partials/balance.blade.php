<table class="lx-table lines">
    <thead>
        <tr><th>Compte</th><th>Intitulé</th><th class="num text-right">Débit $</th><th class="num text-right">Crédit $</th><th class="num text-right">Solde débiteur $</th><th class="num text-right">Solde créditeur $</th></tr>
    </thead>
    <tbody>
        @foreach ($balance['rows'] as $r)
            <tr>
                <td class="font-mono">{{ $r['account']->code }}</td>
                <td>{{ $r['account']->label }}</td>
                <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $r['debit']])</td>
                <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $r['credit']])</td>
                <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $r['balance_debit']])</td>
                <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $r['balance_credit']])</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="font-semibold">
            <td colspan="2">Totaux</td>
            @foreach (['debit', 'credit', 'balance_debit', 'balance_credit'] as $k)
                <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $balance[$k], 'blankZero' => false])</td>
            @endforeach
        </tr>
    </tfoot>
</table>
<p>{{ $balance['balanced'] ? 'La balance est équilibrée : total des débits égal au total des crédits.' : 'Déséquilibre : le total des débits diffère du total des crédits.' }}</p>
