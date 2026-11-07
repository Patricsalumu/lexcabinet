<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Utilisateurs de démonstration (prototype) : un par rôle, plus une avocate
 * qui cumule le rôle secrétaire. Mot de passe de démo : « password ».
 */
class UserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const USERS = [
        ['name' => 'Me Grâce Mukendi', 'title' => 'Associée gérante', 'email' => 'grace.mukendi@mukendi-avocats.example', 'phone' => '+243 81 000 0001', 'roles' => [Role::Admin, Role::Lawyer]],
        ['name' => 'Me Joël Kabeya', 'title' => 'Avocat', 'email' => 'joel.kabeya@mukendi-avocats.example', 'phone' => '+243 81 000 0002', 'roles' => [Role::Lawyer]],
        ['name' => 'Me Sarah Ilunga', 'title' => 'Avocate', 'email' => 'sarah.ilunga@mukendi-avocats.example', 'phone' => '+243 81 000 0003', 'roles' => [Role::Lawyer]],
        ['name' => 'Nadine Lukusa', 'title' => 'Secrétaire', 'email' => 'nadine.lukusa@mukendi-avocats.example', 'phone' => '+243 81 000 0004', 'roles' => [Role::Secretary]],
        ['name' => 'Chris Mbuyi', 'title' => 'Caissier', 'email' => 'chris.mbuyi@mukendi-avocats.example', 'phone' => '+243 81 000 0005', 'roles' => [Role::Cashier]],
        ['name' => 'Fiston Tshibangu', 'title' => 'Comptable', 'email' => 'fiston.tshibangu@mukendi-avocats.example', 'phone' => '+243 81 000 0006', 'roles' => [Role::Accountant]],
        ['name' => 'Me Patience Kanku', 'title' => 'Avocate stagiaire', 'email' => 'patience.kanku@mukendi-avocats.example', 'phone' => '+243 81 000 0007', 'roles' => [Role::Lawyer, Role::Secretary]],
    ];

    public function run(): void
    {
        foreach (self::USERS as $data) {
            $user = User::query()->updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'title' => $data['title'],
                    'phone' => $data['phone'],
                    'password' => self::PASSWORD,
                    'is_active' => true,
                ],
            );
            $user->forceFill(['email_verified_at' => now()])->saveQuietly();

            $user->syncRoles(array_map(fn (Role $r) => $r->value, $data['roles']));
        }
    }
}
