<table class="head">
    <tr>
        <td style="width:60%">
            @if ($logo)<img src="{{ $logo }}" alt="" style="max-height:52px;max-width:180px;margin-bottom:6px"><br>@endif
            <span class="firm">{{ $firm['firm.name'] }}</span><br>
            <span class="small">
                {{ $firm['firm.bar'] }}<br>
                {{ $firm['firm.address'] }}<br>
                @if ($firm['firm.rccm'])RCCM {{ $firm['firm.rccm'] }}@endif
                @if ($firm['firm.id_nat']) · ID. Nat. {{ $firm['firm.id_nat'] }}@endif
                @if ($firm['firm.nif']) · NIF {{ $firm['firm.nif'] }}@endif<br>
                {{ $firm['firm.phone'] }}@if ($firm['firm.email']) · {{ $firm['firm.email'] }}@endif
            </span>
        </td>
        <td style="width:40%;text-align:right">
            <span class="doctitle">{{ $docTitle }}</span><br>
            <span class="mono">{{ $docNumber }}</span><br>
            <span class="small">{!! $docDates !!}</span>
        </td>
    </tr>
</table>
