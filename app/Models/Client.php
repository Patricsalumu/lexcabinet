<?php

namespace App\Models;

use App\Enums\ClientType;
use App\Enums\IdDocumentType;
use App\Enums\Permission;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'type', 'name', 'phone', 'email', 'address',
        'id_document_type', 'id_document_number',
        'rccm', 'id_nat', 'representative',
        'email_consent', 'is_prospect', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ClientType::class,
            'id_document_type' => IdDocumentType::class,
            'email_consent' => 'boolean',
            'is_prospect' => 'boolean',
        ];
    }

    public function cases(): HasMany
    {
        return $this->hasMany(LegalCase::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** CLI-03 : un e-mail ne part que si le client a une adresse et a donné son consentement. */
    public function canReceiveEmails(): bool
    {
        return $this->email_consent && filled($this->email);
    }

    /**
     * Clients visibles : tous pour clients.view_all ; pour un avocat, ceux de ses dossiers.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can(Permission::ClientsViewAll->value)) {
            return $query;
        }

        if ($user->can(Permission::ClientsViewOwn->value)) {
            return $query->whereHas('cases', fn (Builder $q) => $q->assignedTo($user));
        }

        return $query->whereRaw('1 = 0');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('clients')
            ->logFillable()
            ->logExcept(['created_by'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Client créé',
                'updated' => 'Client modifié',
                'deleted' => 'Client supprimé',
                default => $event,
            });
    }
}
