<nav class="mb-6 flex flex-wrap gap-1 border-b border-slate-200" aria-label="E-mails">
    @foreach ([['emails.index', 'Journal d\'envoi', 'emails.view'], ['emails.templates', 'Modèles', 'email_templates.manage']] as [$route, $label, $permission])
        @can($permission)
            <a href="{{ route($route) }}" wire:navigate
               @class([
                   '-mb-px border-b-2 px-3 py-2 text-sm',
                   'border-indigo-800 font-semibold text-slate-900' => request()->routeIs($route),
                   'border-transparent text-slate-600 hover:text-slate-900' => ! request()->routeIs($route),
               ])
               @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
        @endcan
    @endforeach
</nav>
