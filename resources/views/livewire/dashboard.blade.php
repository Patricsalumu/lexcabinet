@php use App\Support\Money; @endphp

<div>
    <x-lex.page-header
        :title="'Bonjour, '.preg_replace('/^Me /', 'Maître ', $user->name)"
        :subtitle="ucfirst(now()->translatedFormat('l j F Y')).($rate ? ' · taux : 1 $ = '.Money::formatRate((string) $rate->rate).' FC' : '')" />

    <x-lex.flash />

    @if ($rateMissing)
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
            <b>Aucun taux saisi aujourd'hui.</b>
            @if ($rate)
                Le dernier taux connu ({{ Money::formatRate((string) $rate->rate) }} FC pour 1 $, du {{ $rate->rate_date->translatedFormat('j F Y') }}) sera utilisé.
            @else
                Aucun taux n'a encore été enregistré.
            @endif
            @can('rates.manage')
                <a href="{{ route('settings.rates') }}" wire:navigate class="font-semibold underline">Saisir le taux du jour</a>
            @endcan
        </div>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        <x-lex.panel title="Votre compte">
            <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
                <dt class="text-slate-500">Fonction</dt>
                <dd>{{ $user->title ?? '—' }}</dd>
                <dt class="text-slate-500">Rôles</dt>
                <dd>{{ implode(', ', $user->roleLabels()) }}</dd>
                <dt class="text-slate-500">Dernière connexion</dt>
                <dd>{{ $user->last_login_at?->translatedFormat('j F Y à H\hi') ?? '—' }}</dd>
            </dl>
        </x-lex.panel>

        <x-lex.panel title="Taux de change">
            @if ($rate)
                <p class="font-mono text-2xl text-slate-900">1 $ = {{ Money::formatRate((string) $rate->rate) }} FC</p>
                <p class="mt-1 text-sm text-slate-500">{{ $rate->source->label() }}, taux du {{ $rate->rate_date->translatedFormat('j F Y') }}</p>
            @else
                <p class="text-sm text-slate-500">Aucun taux enregistré.</p>
            @endif
        </x-lex.panel>
    </div>

    <p class="mt-6 text-sm text-slate-500">
        Les rendez-vous du jour, le solde de caisse, les factures impayées et les dossiers actifs s'afficheront ici au fil des phases suivantes.
    </p>
</div>
