<div>
    <x-lex.page-header title="Plan comptable" subtitle="Inspiré du SYSCOHADA révisé, classes 1 à 7. Un compte mouvementé ne se supprime pas ; il peut être désactivé.">
        <x-slot:actions><a href="{{ route('accounting.index') }}" wire:navigate class="lx-btn">Retour à la comptabilité</a></x-slot:actions>
    </x-lex.page-header>
    <x-lex.flash />

    <div class="grid gap-6 lg:grid-cols-[1fr_minmax(0,20rem)]">
        <div class="flex flex-col gap-6">
            @foreach ($accounts as $class => $list)
                <x-lex.panel :title="'Classe '.$class.' · '.($classes[$class] ?? '')">
                    <div class="-m-5 overflow-x-auto">
                        <table class="lx-table">
                            <tbody>
                                @foreach ($list as $a)
                                    <tr wire:key="acc-{{ $a->id }}" @class(['opacity-50' => ! $a->is_active])>
                                        <td class="w-24 font-mono">{{ $a->code }}</td>
                                        <td>
                                            @if ($editingId === $a->id)
                                                <form wire:submit="saveLabel" class="flex gap-2">
                                                    <label for="el-{{ $a->id }}" class="sr-only">Intitulé</label>
                                                    <input id="el-{{ $a->id }}" type="text" wire:model="editLabel" class="lx-input mt-0">
                                                    <button type="submit" class="lx-btn py-1 text-xs">OK</button>
                                                </form>
                                                @error('editLabel')<p class="lx-error">{{ $message }}</p>@enderror
                                            @else
                                                {{ $a->label }}
                                            @endif
                                        </td>
                                        <td class="w-28 text-xs text-slate-500">{{ $a->lines_count ? $a->lines_count.' ligne(s)' : '' }}</td>
                                        @if ($canManage)
                                            <td class="w-48 whitespace-nowrap text-right">
                                                <button type="button" wire:click="edit({{ $a->id }})" class="lx-btn py-1 text-xs">Renommer</button>
                                                <button type="button" wire:click="toggle({{ $a->id }})" class="lx-btn py-1 text-xs">{{ $a->is_active ? 'Désactiver' : 'Réactiver' }}</button>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-lex.panel>
            @endforeach
        </div>

        @if ($canManage)
            <x-lex.panel title="Ajouter un compte">
                <form wire:submit="add" class="grid gap-3">
                    <div><label for="c-code" class="lx-label">Numéro</label><input id="c-code" type="text" inputmode="numeric" wire:model="code" class="lx-input">@error('code')<p class="lx-error">{{ $message }}</p>@enderror</div>
                    <div><label for="c-label" class="lx-label">Intitulé</label><input id="c-label" type="text" wire:model="label" class="lx-input">@error('label')<p class="lx-error">{{ $message }}</p>@enderror</div>
                    <button type="submit" class="lx-btn-primary">Ajouter</button>
                </form>
            </x-lex.panel>
        @endif
    </div>
</div>
