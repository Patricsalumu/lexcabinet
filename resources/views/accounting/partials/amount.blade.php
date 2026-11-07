{{-- Montant USD : brut pour Excel (valeur numérique), formaté à l'écran et en PDF. --}}
@if (bccomp((string) $value, '0', 2) === 0 && ($blankZero ?? true))
@elseif ($numeric ?? false){{ $value }}@else{{ App\Support\Money::format((string) $value, 'USD') }}@endif
