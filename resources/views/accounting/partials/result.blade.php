<table class="lx-table lines">
    <thead><tr><th>Compte</th><th>Produits</th><th class="num text-right">Montant $</th></tr></thead>
    <tbody>
        @forelse ($result['products'] as $r)
            <tr><td class="font-mono">{{ $r['account']->code }}</td><td>{{ $r['account']->label }}</td><td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $r['amount'], 'blankZero' => false])</td></tr>
        @empty
            <tr><td colspan="3">Aucun produit.</td></tr>
        @endforelse
        <tr class="font-semibold"><td colspan="2">Total des produits</td><td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $result['total_products'], 'blankZero' => false])</td></tr>
    </tbody>
</table>
<table class="lx-table lines" style="margin-top:16px">
    <thead><tr><th>Compte</th><th>Charges</th><th class="num text-right">Montant $</th></tr></thead>
    <tbody>
        @forelse ($result['charges'] as $r)
            <tr><td class="font-mono">{{ $r['account']->code }}</td><td>{{ $r['account']->label }}</td><td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $r['amount'], 'blankZero' => false])</td></tr>
        @empty
            <tr><td colspan="3">Aucune charge.</td></tr>
        @endforelse
        <tr class="font-semibold"><td colspan="2">Total des charges</td><td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $result['total_charges'], 'blankZero' => false])</td></tr>
    </tbody>
    <tfoot>
        <tr class="font-semibold"><td colspan="2">Résultat de la période ({{ bccomp($result['result'], '0', 2) >= 0 ? 'bénéfice' : 'perte' }}), hors amortissements et impôts</td>
            <td class="num text-right font-mono">@include('accounting.partials.amount', ['value' => $result['result'], 'blankZero' => false])</td></tr>
    </tfoot>
</table>
