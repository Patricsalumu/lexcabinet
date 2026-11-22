<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Tenancy\CurrentFirm;
use Illuminate\Database\Seeder;

/**
 * Super administrateur de démonstration. En production, il se crée avec
 * « php artisan lexcabinet:create-super-admin adresse@domaine » (mot de passe saisi masqué).
 */
class SuperAdminDemoSeeder extends Seeder
{
    public function run(): void
    {
        app(CurrentFirm::class)->runWithoutFirm(function () {
            $user = User::query()->withoutGlobalScopes()->firstOrNew(['email' => 'plateforme@lexcabinet.example']);
            $user->fill(['name' => 'Administrateur de la plateforme', 'password' => UserSeeder::PASSWORD, 'is_active' => true]);
            $user->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();
        });
    }
}
