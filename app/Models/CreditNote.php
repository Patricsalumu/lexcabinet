<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Avoir : annule une facture émise. Ni modifiable, ni supprimable.
 */
class CreditNote extends Model
{
    protected $fillable = [
        'number', 'invoice_id', 'client_id', 'currency', 'exchange_rate', 'issue_date',
        'fees_amount', 'other_amount', 'vat_amount', 'total', 'reason', 'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'exchange_rate' => 'decimal:4',
            'issue_date' => 'date',
            'fees_amount' => 'decimal:2',
            'other_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un avoir ne peut pas être modifié.'));
        static::deleting(fn () => throw new LogicException('Un avoir ne peut pas être supprimé.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
