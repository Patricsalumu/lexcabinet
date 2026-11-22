@php
    use App\Enums\Module;
    $settings = app(\App\Services\SettingsService::class);
    $user = auth()->user();
    $isPlatform = $user->isSuperAdmin();
    $firmName = $isPlatform ? 'Plateforme' : $settings->get('firm.name');
    $hasLogo = ! auth()->user()->isSuperAdmin() && $settings->get('firm.logo_path') !== null;
    $user = auth()->user();
@endphp

<nav class="flex flex-col gap-5 border-slate-200 bg-white px-3 py-5 lg:sticky lg:top-0 lg:h-screen lg:overflow-y-auto lg:border-r"
     aria-label="Modules">
    <div class="flex items-center gap-3 px-2">
        @if ($hasLogo)
            <img src="{{ route('firm.logo') }}" alt="" class="h-9 w-9 rounded object-contain">
        @endif
        <div>
            <a href="{{ $isPlatform ? route('platform.firms') : route('dashboard') }}" wire:navigate class="font-display text-lg font-semibold text-slate-900">LexCabinet</a>
            <p class="text-xs text-slate-500">{{ $firmName }}</p>
        </div>
    </div>

    <div class="-mx-3 flex gap-1 overflow-x-auto px-3 lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0">
        @if ($isPlatform)
            @foreach ([['platform.firms', 'Cabinets', 'platform.firms*'], ['platform.audit', 'Journal de la plateforme', 'platform.audit']] as [$route, $label, $pattern])
                @php $active = request()->routeIs($pattern); @endphp
                <a href="{{ route($route) }}" wire:navigate
                   @class([
                       'whitespace-nowrap rounded-md px-3 py-2 text-sm',
                       'bg-indigo-50 font-semibold text-slate-900' => $active,
                       'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $active,
                   ])
                   @if ($active) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        @endif
        @foreach ($isPlatform ? [] : Module::cases() as $module)
            @can('view-module', $module->value)
                @php
                    $url = $module->url();
                    $active = $module->isCurrent();
                @endphp
                <a href="{{ $url }}" wire:navigate
                   @class([
                       'whitespace-nowrap rounded-md px-3 py-2 text-sm',
                       'bg-indigo-50 font-semibold text-slate-900' => $active,
                       'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $active,
                   ])
                   @if ($active) aria-current="page" @endif>{{ $module->label() }}@if ($module === Module::Messages && ($unread = app(\App\Services\ConversationService::class)->unreadCount($user)) > 0)<span class="ml-2 rounded-full bg-indigo-700 px-1.5 py-0.5 text-xs font-semibold text-white" aria-label="{{ $unread }} non lue(s)">{{ $unread }}</span>@endif</a>
            @endcan
        @endforeach
    </div>

    <div class="border-t border-slate-200 px-2 pt-4 text-sm lg:mt-auto">
        <p class="font-medium text-slate-900">{{ $user->name }}</p>
        <p class="text-xs text-slate-500">{{ implode(' · ', $user->roleLabels()) }}</p>
        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 lg:flex-col">
            <a href="{{ route('profile') }}" wire:navigate class="text-slate-600 hover:text-slate-900">Mon profil</a>
            <a href="{{ route('two-factor.setup') }}" wire:navigate class="text-slate-600 hover:text-slate-900">Double authentification</a>
            <livewire:layout.logout-button />
        </div>
    </div>
</nav>
