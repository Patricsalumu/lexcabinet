@php
    $label = 'block text-xs font-medium text-slate-600';
@endphp

<div>
    <x-lex.page-header title="Paramètres" subtitle="Messagerie du cabinet : serveur d'envoi et boîte où arrivent les réponses des clients." />
    <x-lex.settings-tabs />
    <x-lex.flash />

    <div class="mb-6 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
        <p><span class="font-medium">Envoi :</span>
            {{ $usesOwnSmtp ? 'par le serveur du cabinet.' : 'par la plateforme ('.$platformFrom.'), au nom du cabinet.' }}</p>
        <p><span class="font-medium">Les clients répondent à :</span> {{ $replyAddress }} <span class="text-slate-500">(e-mail du cabinet, onglet « Cabinet »)</span></p>
        <p><span class="font-medium">Réponses relevées dans :</span>
            {{ $readsOwnMailbox ? 'la boîte du cabinet ('.$imap_username.').' : 'la boîte de la plateforme.' }}</p>
        <p class="mt-2 text-xs text-slate-500">Pour retrouver les réponses dans l'application, l'e-mail du cabinet et la boîte lue doivent être la même adresse.</p>
    </div>

    @if ($testResult)
        <div @class(['mb-4 rounded-md border p-3 text-sm', 'border-emerald-200 bg-emerald-50 text-emerald-800' => $testOk, 'border-red-200 bg-red-50 text-red-800' => ! $testOk]) role="status">{{ $testResult }}</div>
    @endif

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-2">
        <x-lex.panel title="Serveur d'envoi (SMTP)" note="Facultatif">
            <label class="mb-4 flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="smtp_enabled" class="rounded border-slate-300"> Envoyer les e-mails du cabinet par son propre serveur
            </label>
            <div @class(['grid gap-4 sm:grid-cols-2', 'opacity-60' => ! $smtp_enabled])>
                <div class="sm:col-span-2">
                    <label for="s-host" class="{{ $label }}">Serveur</label>
                    <input id="s-host" type="text" wire:model="smtp_host" class="lx-input" placeholder="mail.exemple.com">
                    @error('smtp_host')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="s-port" class="{{ $label }}">Port</label>
                    <input id="s-port" type="number" wire:model="smtp_port" class="lx-input">
                    @error('smtp_port')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="s-enc" class="{{ $label }}">Chiffrement</label>
                    <select id="s-enc" wire:model="smtp_encryption" class="lx-input">
                        <option value="ssl">SSL (port 465)</option>
                        <option value="tls">STARTTLS (port 587)</option>
                    </select>
                </div>
                <div>
                    <label for="s-user" class="{{ $label }}">Identifiant (adresse du compte)</label>
                    <input id="s-user" type="text" wire:model="smtp_username" class="lx-input" autocomplete="off">
                    @error('smtp_username')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="s-pass" class="{{ $label }}">Mot de passe {{ $hasSmtpPassword ? '(enregistré ; laisser vide pour le garder)' : '' }}</label>
                    <input id="s-pass" type="password" wire:model="smtp_password" class="lx-input" autocomplete="new-password">
                    @error('smtp_password')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="s-from" class="{{ $label }}">Adresse d'expédition (par défaut : l'identifiant)</label>
                    <input id="s-from" type="email" wire:model="from_address" class="lx-input">
                    @error('from_address')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
            </div>
            <button type="button" wire:click="testSmtp" class="lx-btn mt-4" wire:loading.attr="disabled">Envoyer un e-mail d'essai</button>
            <p class="lx-hint mt-2">L'essai part par le serveur enregistré, vers votre propre adresse.</p>
        </x-lex.panel>

        <x-lex.panel title="Boîte de réception (IMAP)" note="Facultatif">
            <label class="mb-4 flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="imap_enabled" class="rounded border-slate-300"> Relever les réponses dans la boîte du cabinet
            </label>
            <div @class(['grid gap-4 sm:grid-cols-2', 'opacity-60' => ! $imap_enabled])>
                <div class="sm:col-span-2">
                    <label for="i-host" class="{{ $label }}">Serveur</label>
                    <input id="i-host" type="text" wire:model="imap_host" class="lx-input" placeholder="mail.exemple.com">
                    @error('imap_host')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="i-port" class="{{ $label }}">Port</label>
                    <input id="i-port" type="number" wire:model="imap_port" class="lx-input">
                    @error('imap_port')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="i-enc" class="{{ $label }}">Chiffrement</label>
                    <select id="i-enc" wire:model="imap_encryption" class="lx-input">
                        <option value="ssl">SSL (port 993)</option>
                        <option value="starttls">STARTTLS (port 143)</option>
                        <option value="tls">TLS</option>
                    </select>
                </div>
                <div>
                    <label for="i-user" class="{{ $label }}">Identifiant (adresse de la boîte)</label>
                    <input id="i-user" type="text" wire:model="imap_username" class="lx-input" autocomplete="off">
                    @error('imap_username')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="i-pass" class="{{ $label }}">Mot de passe {{ $hasImapPassword ? '(enregistré ; laisser vide pour le garder)' : '' }}</label>
                    <input id="i-pass" type="password" wire:model="imap_password" class="lx-input" autocomplete="new-password">
                    @error('imap_password')<p class="lx-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="i-folders" class="{{ $label }}">Dossiers relevés (séparés par des virgules)</label>
                    <input id="i-folders" type="text" wire:model="imap_folders" class="lx-input">
                </div>
                <div>
                    <label for="i-sent" class="{{ $label }}">Dossier des messages envoyés</label>
                    <input id="i-sent" type="text" wire:model="imap_sent_folder" class="lx-input">
                </div>
            </div>
            <button type="button" wire:click="testImap" class="lx-btn mt-4" wire:loading.attr="disabled">Tester la connexion</button>
            <p class="lx-hint mt-2">La boîte est lue sans rien y changer : aucun message n'est supprimé, déplacé ni marqué comme lu.</p>
        </x-lex.panel>

        <div class="lg:col-span-2">
            <button type="submit" class="lx-btn-primary">Enregistrer</button>
            <p class="lx-hint mt-2">Les mots de passe sont chiffrés et ne sont jamais réaffichés.</p>
        </div>
    </form>
</div>
