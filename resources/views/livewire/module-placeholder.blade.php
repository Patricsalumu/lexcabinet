<div>
    <x-lex.page-header :title="$moduleEnum->label()" />

    <x-lex.panel>
        <p class="text-sm text-slate-600">
            Ce module sera livré en phase {{ $moduleEnum->phase() }}. Vos droits d'accès sont déjà en place.
        </p>
    </x-lex.panel>
</div>
