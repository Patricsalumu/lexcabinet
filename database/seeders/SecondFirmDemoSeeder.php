<?php

namespace Database\Seeders;

use App\Enums\ClientType;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Firm;
use App\Models\User;
use App\Services\FirmProvisioner;
use App\Support\Tenancy\CurrentFirm;
use Illuminate\Database\Seeder;

/**
 * Second cabinet de démonstration, avec ses propres utilisateurs et clients : sert à vérifier
 * l'étanchéité entre cabinets (un utilisateur de Mukendi ne voit rien de Kasongo, et inversement).
 */
class SecondFirmDemoSeeder extends Seeder
{
    public function run(FirmProvisioner $provisioner): void
    {
        $firm = Firm::query()->where('name', 'Cabinet Kasongo & Partenaires')->first()
            ?? $provisioner->create('Cabinet Kasongo & Partenaires', [
                'firm.address' => '45, boulevard du 30 Juin, Gombe, Kinshasa',
                'firm.phone' => '+243 99 000 0000',
                'firm.email' => 'contact@kasongo-avocats.example',
            ]);

        app(CurrentFirm::class)->run($firm, function () {
            foreach ([
                ['name' => 'Me Albert Kasongo', 'title' => 'Associé gérant', 'email' => 'albert.kasongo@kasongo-avocats.example', 'roles' => [Role::Admin, Role::Lawyer]],
                ['name' => 'Ruth Mwamba', 'title' => 'Secrétaire', 'email' => 'ruth.mwamba@kasongo-avocats.example', 'roles' => [Role::Secretary]],
            ] as $data) {
                $user = User::query()->updateOrCreate(['email' => $data['email']], [
                    'name' => $data['name'], 'title' => $data['title'], 'password' => UserSeeder::PASSWORD, 'is_active' => true,
                ]);
                $user->forceFill(['email_verified_at' => now()])->saveQuietly();
                $user->syncRoles(array_map(fn (Role $r) => $r->value, $data['roles']));
            }

            Client::query()->firstOrCreate(['name' => 'Brasserie du Lac SARL'], [
                'type' => ClientType::Company, 'email' => 'direction@brasserie-du-lac.example', 'phone' => '+243 99 111 2233', 'email_consent' => true,
            ]);
            Client::query()->firstOrCreate(['name' => 'Odette Mbala'], [
                'type' => ClientType::Individual, 'email' => 'odette.mbala@example.cd', 'phone' => '+243 99 444 5566', 'email_consent' => true,
            ]);
        });
    }
}
