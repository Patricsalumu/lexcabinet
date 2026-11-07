<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\FeeType;
use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Facture (FAC-01 à FAC-14). Un brouillon est modifiable et supprimable ;
 * une facture émise est figée : seuls son statut, ses encaissements et son annulation évoluent.
 */
class Invoice extends Model
{
    use LogsActivity;

    /** Champs qui peuvent encore changer après l'émission. */
    public const MUTABLE_AFTER_ISSUE = ['status', 'paid_amount', 'cancelled_at', 'updated_at'];

    protected $fillable = [
        'number', 'case_id', 'client_id', 'appointment_id', 'fee_type', 'status', 'currency', 'exchange_rate',
        'issue_date', 'payment_terms_days', 'due_date', 'period_start', 'period_end',
        'discount_percent', 'vat_enabled', 'vat_rate',
        'fees_total', 'discount_amount', 'other_total', 'taxable_base', 'vat_amount', 'total', 'paid_amount',
        'notes', 'issued_at', 'issued_by', 'cancelled_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'currency' => Currency::class,
            'fee_type' => FeeType::class,
            'exchange_rate' => 'decimal:4',
            'issue_date' => 'date',
            'due_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'discount_percent' => 'decimal:2',
            'vat_enabled' => 'boolean',
            'vat_rate' => 'decimal:2',
            'fees_total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'other_total' => 'decimal:2',
            'taxable_base' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice) {
            $original = InvoiceStatus::from($invoice->getRawOriginal('status'));
            if ($original->isLocked() && array_diff(array_keys($invoice->getDirty()), self::MUTABLE_AFTER_ISSUE) !== []) {
                throw new LogicException('Une facture émise ne peut pas être modifiée : annulez-la par un avoir.');
            }
        });

        static::deleting(function (Invoice $invoice) {
            if (InvoiceStatus::from($invoice->getRawOriginal('status'))->isLocked()) {
                throw new LogicException('Une facture émise ne peut pas être supprimée : annulez-la par un avoir.');
            }
        });
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position')->orderBy('id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CashMovement::class)->where('type', \App\Enums\CashMovementType::Receipt->value);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AdvanceAllocation::class);
    }

    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function balanceDue(): string
    {
        if ($this->status === InvoiceStatus::Cancelled || $this->status === InvoiceStatus::Draft) {
            return '0.00';
        }

        $rest = bcsub((string) $this->total, (string) $this->paid_amount, 2);

        return bccomp($rest, '0', 2) < 0 ? '0.00' : $rest;
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->due_date !== null && $this->due_date->lt(today());
    }

    /** Libellé affiché : statut, avec « échue » si l'échéance est dépassée. */
    public function statusLabel(): string
    {
        return $this->isOverdue() ? $this->status->label().', échue' : $this->status->label();
    }

    public function displayNumber(): string
    {
        return $this->number ?? 'Brouillon n° '.$this->id;
    }

    /**
     * Factures visibles : toutes pour invoices.view_all ; pour un avocat, celles de ses dossiers.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can(Permission::InvoicesViewAll->value)) {
            return $query;
        }

        if ($user->can(Permission::InvoicesViewOwn->value)) {
            return $query->whereHas('legalCase', fn (Builder $q) => $q->assignedTo($user));
        }

        return $query->whereRaw('1 = 0');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('invoices')
            ->logOnly(['number', 'status', 'total', 'discount_percent', 'vat_enabled', 'due_date', 'paid_amount'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Brouillon de facture créé',
                'updated' => 'Facture modifiée',
                'deleted' => 'Brouillon de facture supprimé',
                default => $event,
            });
    }
}
