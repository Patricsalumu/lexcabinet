<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    /** Nombre d'échecs de connexion avant verrouillage du compte. */
    public const MAX_FAILED_LOGINS = 5;

    protected $fillable = [
        'name',
        'title',
        'email',
        'phone',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'locked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['name', 'title', 'email', 'phone', 'is_active', 'locked_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Utilisateur créé',
                'updated' => 'Utilisateur modifié',
                'deleted' => 'Utilisateur supprimé',
                default => $event,
            });
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin->value);
    }

    /** La double authentification est exigée pour les administrateurs. */
    public function requiresTwoFactor(): bool
    {
        return config('lexcabinet.admin_two_factor') && $this->isAdmin();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** @return list<string> Libellés français des rôles. */
    public function roleLabels(): array
    {
        return $this->roles
            ->map(fn ($role) => Role::tryFrom($role->name)?->label() ?? $role->name)
            ->values()
            ->all();
    }
}
