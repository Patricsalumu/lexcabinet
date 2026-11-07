@php
    $input = 'mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-700 focus:ring-indigo-700';
    $label = 'block text-xs font-medium text-slate-600';
@endphp

<div>
    <x-lex.page-header title="Paramètres" subtitle="Qui a créé, modifié, annulé quoi et quand." />
    <x-lex.settings-tabs />

    <x-lex.panel title="Journal d'audit" note="Non modifiable">
        <div class="mb-4 grid gap-3 sm:grid-cols-4">
            <div>
                <label for="a-search" class="{{ $label }}">Action</label>
                <input id="a-search" type="search" wire:model.live.debounce.400ms="search" class="{{ $input }}" placeholder="Rechercher…">
            </div>
            <div>
                <label for="a-user" class="{{ $label }}">Utilisateur</label>
                <select id="a-user" wire:model.live="userId" class="{{ $input }}">
                    <option value="">Tous</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="a-from" class="{{ $label }}">Du</label>
                <input id="a-from" type="date" wire:model.live="from" class="{{ $input }}">
            </div>
            <div>
                <label for="a-to" class="{{ $label }}">Au</label>
                <input id="a-to" type="date" wire:model.live="to" class="{{ $input }}">
            </div>
        </div>

        <div class="-mx-5 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2">Date</th>
                        <th class="px-4 py-2">Utilisateur</th>
                        <th class="px-4 py-2">Action</th>
                        <th class="px-4 py-2">Détail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($entries as $entry)
                        @php
                            $props = $entry->properties ?? collect();
                            $subject = $entry->subject;
                            $detail = collect();
                            if ($subject) {
                                $detail->push($subject->name ?? (method_exists($subject, 'getKey') ? class_basename($subject).' #'.$subject->getKey() : ''));
                            }
                            if ($props->has('old') && $props->has('new') && ! is_array($props['new'])) {
                                $detail->push(($props['old'] ?? '—').' → '.($props['new'] ?? '—'));
                            } elseif ($props->has('old') && $props->has('new')) {
                                $detail->push(implode(', ', (array) $props['old']).' → '.implode(', ', (array) $props['new']));
                            } elseif ($entry->attribute_changes?->has('attributes')) {
                                $detail->push(collect($entry->attribute_changes['attributes'])->map(fn ($v, $k) => $k.' : '.(is_scalar($v) || $v === null ? ($v ?? '—') : json_encode($v)))->implode(' · '));
                            }
                        @endphp
                        <tr wire:key="audit-{{ $entry->id }}">
                            <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">{{ $entry->created_at->format('d/m/Y H:i:s') }}</td>
                            <td class="px-4 py-2">{{ $entry->causer?->name ?? 'Système' }}</td>
                            <td class="px-4 py-2">{{ $entry->description }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ $detail->filter()->implode(' — ') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Aucune action enregistrée.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $entries->links() }}</div>
    </x-lex.panel>
</div>
