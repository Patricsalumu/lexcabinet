<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class JournalLine extends Model
{
    use BelongsToFirm;

    protected $fillable = ['journal_entry_id', 'account_id', 'label', 'debit', 'credit', 'original_currency', 'original_amount', 'exchange_rate'];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'original_currency' => Currency::class,
            'original_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une ligne d\'écriture ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une ligne d\'écriture ne se supprime pas.'));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
