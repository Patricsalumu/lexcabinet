@props(['title' => null, 'note' => null])

<section {{ $attributes->merge(['class' => 'rounded-lg border border-slate-200 bg-white shadow-sm']) }}>
    @if ($title)
        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-200 px-5 py-3">
            <h2 class="font-display text-lg font-semibold text-slate-900">{{ $title }}</h2>
            @if ($note)
                <span class="text-xs text-slate-500">{{ $note }}</span>
            @endif
        </div>
    @endif
    <div class="p-5">{{ $slot }}</div>
</section>
