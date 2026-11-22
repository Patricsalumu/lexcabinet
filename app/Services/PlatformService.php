<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\EmailLog;
use App\Models\Firm;
use App\Models\User;
use App\Support\Tenancy\CurrentFirm;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Espace Plateforme (phase M2) : le super administrateur crée les cabinets et leurs
 * administrateurs, renvoie un lien d'accès, suspend ou réactive un cabinet.
 * Il ne lit jamais les données métier des cabinets.
 */
class PlatformService
{
    public function __construct(
        private readonly FirmProvisioner $provisioner,
        private readonly UserService $users,
        private readonly CurrentFirm $current,
    ) {}

    /**
     * Crée un cabinet prêt à l'emploi et son premier administrateur, qui reçoit un lien sécurisé
     * pour définir son mot de passe. Aucun mot de passe n'est saisi ni envoyé.
     *
     * @param  array{name: string, email?: ?string, phone?: ?string, address?: ?string}  $firmData
     * @param  array{name: string, email: string, phone?: ?string, title?: ?string}  $adminData
     * @return array{0: Firm, 1: User}
     */
    public function createFirm(array $firmData, array $adminData, User $by): array
    {
        $this->assertSuperAdmin($by);
        $this->assertEmailFree($adminData['email']);

        return $this->current->runWithoutFirm(fn () => DB::transaction(function () use ($firmData, $adminData, $by) {
            $firm = $this->provisioner->create(trim($firmData['name']), array_filter([
                'firm.email' => $firmData['email'] ?? null,
                'firm.phone' => $firmData['phone'] ?? null,
                'firm.address' => $firmData['address'] ?? null,
            ], fn ($v) => filled($v)));

            $admin = $this->createAdmin($firm, $adminData, $by);

            activity('platform')->causedBy($by)->performedOn($firm)
                ->withProperties(['admin' => $admin->email])
                ->log('Cabinet créé, avec son administrateur');

            return [$firm, $admin];
        }));
    }

    /** @param  array{name: string, email: string, phone?: ?string, title?: ?string}  $data */
    public function addAdmin(Firm $firm, array $data, User $by): User
    {
        $this->assertSuperAdmin($by);
        $this->assertEmailFree($data['email']);

        return DB::transaction(function () use ($firm, $data, $by) {
            $admin = $this->createAdmin($firm, $data, $by);
            activity('platform')->causedBy($by)->performedOn($firm)
                ->withProperties(['admin' => $admin->email])
                ->log('Administrateur ajouté au cabinet');

            return $admin;
        });
    }

    /** Renvoie le lien d'accès d'un administrateur du cabinet (lien expiré, e-mail non reçu…). */
    public function resendAdminAccess(Firm $firm, User $admin, User $by): EmailLog
    {
        $this->assertSuperAdmin($by);
        abort_unless($admin->firm_id === $firm->id && $admin->isAdmin(), 404);

        return $this->current->run($firm, function () use ($firm, $admin, $by) {
            $log = $this->users->resendAccess($admin, $by);
            activity('platform')->causedBy($by)->performedOn($firm)
                ->withProperties(['admin' => $admin->email])
                ->log('Lien d\'accès renvoyé à un administrateur');

            return $log;
        });
    }

    /** Suspend (ou rétablit) l'accès d'un cabinet : ses utilisateurs ne peuvent plus se connecter ; rien n'est effacé. */
    public function setActive(Firm $firm, bool $active, User $by, ?string $reason = null): void
    {
        $this->assertSuperAdmin($by);
        if ($firm->is_active === $active) {
            return;
        }

        if (! $active && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Indiquez le motif de la suspension.']);
        }

        $firm->update(['is_active' => $active]);
        activity('platform')->causedBy($by)->performedOn($firm)
            ->withProperties(array_filter(['reason' => $reason]))
            ->log($active ? 'Accès du cabinet rétabli' : 'Accès du cabinet suspendu');
    }

    public function rename(Firm $firm, string $name, User $by): void
    {
        $this->assertSuperAdmin($by);
        $firm->update(['name' => trim($name)]);
    }

    /** Administrateurs du cabinet (comptes uniquement : nom, e-mail, état). */
    public function admins(Firm $firm)
    {
        return User::query()->withoutGlobalScopes()->where('firm_id', $firm->id)->role(Role::Admin->value)->orderBy('name')->get();
    }

    /** @param  array{name: string, email: string, phone?: ?string, title?: ?string}  $data */
    private function createAdmin(Firm $firm, array $data, User $by): User
    {
        return $this->current->run($firm, fn () => $this->users->create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'title' => $data['title'] ?? 'Administrateur du cabinet',
        ], [Role::Admin->value], $by));
    }

    private function assertEmailFree(string $email): void
    {
        if (User::query()->withoutGlobalScopes()->where('email', strtolower(trim($email)))->exists()) {
            throw ValidationException::withMessages(['admin_email' => 'Cette adresse e-mail est déjà utilisée par un compte de la plateforme.']);
        }
    }

    private function assertSuperAdmin(User $by): void
    {
        abort_unless($by->isSuperAdmin(), 403);
    }
}
