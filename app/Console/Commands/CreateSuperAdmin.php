<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Tenancy\CurrentFirm;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Crée le compte super administrateur de la plateforme (ou promeut un compte sans cabinet).
 * Le mot de passe est saisi de façon masquée dans le terminal : il n'est ni affiché, ni
 * enregistré dans un fichier, ni envoyé par e-mail.
 */
#[Signature('lexcabinet:create-super-admin {email : Adresse e-mail (identifiant de connexion)} {--name= : Nom affiché}')]
#[Description('Crée le compte super administrateur de la plateforme')]
class CreateSuperAdmin extends Command
{
    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = (string) ($this->option('name') ?: 'Administrateur de la plateforme');

        $existing = User::query()->withoutGlobalScopes()->where('email', $email)->first();
        if ($existing && $existing->firm_id !== null) {
            $this->components->error('Cette adresse appartient au compte d\'un cabinet : choisissez une autre adresse pour le super administrateur.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Mot de passe (12 caractères minimum, saisie masquée)');
        $confirmation = (string) $this->secret('Confirmez le mot de passe');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            ['email' => ['required', 'email'], 'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()]],
            [], ['password' => 'mot de passe'],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        app(CurrentFirm::class)->runWithoutFirm(function () use ($existing, $email, $name, $password) {
            $user = $existing ?? new User(['email' => $email]);
            $user->fill(['name' => $existing?->name ?? $name, 'password' => $password, 'is_active' => true]);
            $user->forceFill(['is_super_admin' => true, 'email_verified_at' => now(), 'locked_at' => null, 'failed_login_attempts' => 0])->save();

            activity('platform')->performedOn($user)->log($existing ? 'Compte promu super administrateur (console)' : 'Super administrateur créé (console)');
        });

        $this->components->info("Super administrateur prêt : {$email}. Connectez-vous puis ouvrez « Cabinets ».");

        return self::SUCCESS;
    }
}
