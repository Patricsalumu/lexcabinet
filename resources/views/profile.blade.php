<x-app-layout>
    <x-lex.page-header title="Mon profil" subtitle="Vos informations et votre mot de passe. Un compte ne se supprime pas : seul l'administrateur peut le désactiver." />

    <div class="max-w-3xl space-y-6">
        <x-lex.panel>
            <div class="max-w-xl">
                <livewire:profile.update-profile-information-form />
            </div>
        </x-lex.panel>

        <x-lex.panel>
            <div class="max-w-xl">
                <livewire:profile.update-password-form />
            </div>
        </x-lex.panel>
    </div>
</x-app-layout>
