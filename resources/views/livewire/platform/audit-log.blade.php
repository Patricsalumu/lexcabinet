<div>
    <x-lex.page-header title="Journal de la plateforme" subtitle="Actions du super administrateur. Non modifiable. Les journaux d'audit des cabinets restent propres à chaque cabinet." />

    <x-lex.panel>
        <div class="-m-5 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2">Date</th>
                        <th class="px-4 py-2">Par</th>
                        <th class="px-4 py-2">Action</th>
                        <th class="px-4 py-2">Cabinet</th>
                        <th class="px-4 py-2">Détail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($entries as $entry)
                        @php $props = collect($entry->properties ?? []); @endphp
                        <tr wire:key="pa-{{ $entry->id }}">
                            <td class="whitespace-nowrap px-4 py-2">{{ $entry->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2">{{ $entry->causer?->name ?? 'Système' }}</td>
                            <td class="px-4 py-2">{{ $entry->description }}</td>
                            <td class="px-4 py-2">{{ $entry->subject instanceof \App\Models\Firm ? $entry->subject->name : '—' }}</td>
                            <td class="px-4 py-2 text-xs text-slate-600">
                                @foreach ($props->only(['admin', 'reason', 'attributes', 'old']) as $key => $value)
                                    <span class="block">{{ ['admin' => 'Administrateur', 'reason' => 'Motif', 'attributes' => 'Nouveau', 'old' => 'Ancien'][$key] }} : {{ is_array($value) ? collect($value)->map(fn ($v, $k) => $k.' = '.(is_bool($v) ? ($v ? 'oui' : 'non') : $v))->implode(', ') : $value }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">Aucune action.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-8">{{ $entries->links() }}</div>
    </x-lex.panel>
</div>
