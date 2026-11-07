<?php

namespace App\Models;

use App\Enums\CaseStatus;
use App\Enums\Currency;
use App\Enums\FeeType;
use App\Enums\Matter;
use App\Enums\Permission;
use Database\Factories\LegalCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Dossier (affaire). « Case » étant un mot réservé en PHP, le modèle s'appelle LegalCase.
 */
class LegalCase extends Model
{
    /** @use HasFactory<LegalCaseFactory> */
    use HasFactory, LogsActivity;

    protected $table = 'cases';

    protected $fillable = [
        'reference', 'title', 'client_id', 'adverse_party', 'lead_lawyer_id',
        'matter', 'jurisdiction', 'docket_number', 'status',
        'fee_type', 'fee_amount', 'currency',
        'opened_on', 'next_subscription_invoice_on', 'closed_on', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CaseStatus::class,
            'matter' => Matter::class,
            'fee_type' => FeeType::class,
            'currency' => Currency::class,
            'fee_amount' => 'decimal:2',
            'opened_on' => 'date',
            'next_subscription_invoice_on' => 'date',
            'closed_on' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function leadLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_lawyer_id');
    }

    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'case_user', 'case_id', 'user_id')->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class, 'case_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'case_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'case_id');
    }

    /** Responsable ou collaborateur du dossier. */
    public function isAssignedTo(User $user): bool
    {
        return $this->lead_lawyer_id === $user->id
            || $this->collaborators()->whereKey($user->id)->exists();
    }

    public function scopeAssignedTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('lead_lawyer_id', $user->id)
            ->orWhereHas('collaborators', fn (Builder $c) => $c->whereKey($user->id)));
    }

    /**
     * Dossiers visibles : tous pour cases.view_all ; pour un avocat, ses dossiers
     * et ceux où il est collaborateur (secret professionnel).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can(Permission::CasesViewAll->value)) {
            return $query;
        }

        if ($user->can(Permission::CasesViewOwn->value)) {
            return $query->assignedTo($user);
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [CaseStatus::Closed->value, CaseStatus::Archived->value]);
    }

    /** Minutes facturables non encore facturées (alimentent la facturation au taux horaire). */
    public function unbilledMinutes(): int
    {
        return (int) $this->timeEntries()->where('billable', true)->whereNull('invoice_id')->sum('minutes');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('cases')
            ->logFillable()
            ->logExcept(['created_by'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Dossier ouvert',
                'updated' => 'Dossier modifié',
                'deleted' => 'Dossier supprimé',
                default => $event,
            });
    }
}
