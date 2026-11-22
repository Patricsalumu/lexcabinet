<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use App\Enums\CashMovementType;
use App\Enums\Currency;
use App\Enums\DisbursementCategory;
use App\Enums\MovementStatus;
use App\Enums\PaymentMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Mouvement de trésorerie. Aucune suppression (CAI-08) ; une fois enregistré, seul le statut
 * d'un décaissement en attente évolue (validation ou refus). Une erreur se corrige par contre-passation.
 */
class CashMovement extends Model
{
    use BelongsToFirm;

    public const IN = 'in';

    public const OUT = 'out';

    /** Champs modifiables après l'enregistrement (validation d'un décaissement en attente). */
    private const MUTABLE = ['status', 'validated_by', 'validated_at', 'rejection_reason', 'updated_at'];

    protected $fillable = [
        'type', 'direction', 'status', 'occurred_at', 'cash_session_id', 'treasury_account_id', 'currency', 'amount',
        'exchange_rate', 'counter_account_id', 'counter_currency', 'counter_amount', 'mode', 'reference',
        'receipt_number', 'client_id', 'invoice_id', 'invoice_amount', 'category', 'beneficiary', 'voucher',
        'label', 'reverses_id', 'validated_by', 'validated_at', 'rejection_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'status' => MovementStatus::class,
            'currency' => Currency::class,
            'counter_currency' => Currency::class,
            'mode' => PaymentMode::class,
            'category' => DisbursementCategory::class,
            'occurred_at' => 'datetime',
            'validated_at' => 'datetime',
            'amount' => 'decimal:2',
            'counter_amount' => 'decimal:2',
            'invoice_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CashMovement $movement) {
            if (array_diff(array_keys($movement->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Un mouvement de caisse ne se modifie pas : corrigez-le par une contre-passation.');
            }
        });

        static::deleting(fn () => throw new LogicException('Un mouvement de caisse ne se supprime pas : corrigez-le par une contre-passation.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class, 'treasury_account_id');
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(TreasuryAccount::class, 'counter_account_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AdvanceAllocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /** Provision reçue sans facture (FAC-13). */
    public function isAdvance(): bool
    {
        return $this->type === CashMovementType::Receipt && $this->invoice_id === null;
    }

    /** Montant signé sur le compte principal (+ entrée, - sortie). */
    public function signedAmount(): string
    {
        return $this->direction === self::IN ? (string) $this->amount : '-'.$this->amount;
    }
}
