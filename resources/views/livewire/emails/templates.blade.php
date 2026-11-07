<div>
    <x-lex.page-header title="E-mails" subtitle="Modèles envoyés aux clients et aux avocats" />
    <x-lex.email-tabs />
    <x-lex.flash />

    <div class="lx-warn mb-6">
        Variables disponibles :
        @foreach ($variables as $v)<code class="mr-1 font-mono text-xs">{{ '{'.$v.'}' }}</code>@endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        @foreach ($templates as $key => $tpl)
            <x-lex.panel :title="$names[$key] ?? $key" wire:key="tpl-{{ $key }}">
                <form wire:submit="save('{{ $key }}')" class="grid gap-3">
                    <div>
                        <label for="tpl-s-{{ $key }}" class="lx-label">Objet</label>
                        <input id="tpl-s-{{ $key }}" type="text" wire:model="templates.{{ $key }}.subject" class="lx-input">
                        @error("templates.{$key}.subject") <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="tpl-b-{{ $key }}" class="lx-label">Message</label>
                        <textarea id="tpl-b-{{ $key }}" wire:model="templates.{{ $key }}.body" rows="9" class="lx-input font-mono text-xs"></textarea>
                        @error("templates.{$key}.body") <p class="lx-error">{{ $message }}</p> @enderror
                    </div>
                    <div><button type="submit" class="lx-btn">Enregistrer ce modèle</button></div>
                </form>
            </x-lex.panel>
        @endforeach
    </div>
</div>
