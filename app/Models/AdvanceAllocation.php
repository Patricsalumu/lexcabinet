<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Imputation d'une provision sur une facture du même client (FAC-13). Non modifiable.
 */
class AdvanceAllocation extends Model
{
    use BelongsToFirm;

    protected $fillable = ['cash_movement_id', 'invoice_id', 'amount', 'invoice_amount', 'exchange_rate', 'created_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'invoice_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une imputation ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une imputation ne se supprime pas.'));
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
