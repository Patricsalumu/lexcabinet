<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use App\Enums\InvoiceLineType;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InvoiceLine extends Model
{
    use BelongsToFirm;

    public const AUTO = 'auto';

    public const MANUAL = 'manual';

    protected $fillable = ['invoice_id', 'position', 'origin', 'type', 'label', 'quantity', 'unit_price', 'amount', 'taxable', 'time_entry_ids'];

    protected function casts(): array
    {
        return [
            'type' => InvoiceLineType::class,
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'taxable' => 'boolean',
            'time_entry_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Les lignes d'une facture émise sont figées.
        $guard = function (InvoiceLine $line) {
            $status = Invoice::query()->whereKey($line->invoice_id)->toBase()->value('status');
            if ($status !== null && InvoiceStatus::from($status)->isLocked()) {
                throw new LogicException('Les lignes d\'une facture émise ne peuvent pas être modifiées.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isAuto(): bool
    {
        return $this->origin === self::AUTO;
    }

    /** Quantité affichée : « 1 », « 2,5 », « 8,5 ». */
    public function quantityLabel(): string
    {
        $q = rtrim(rtrim((string) $this->quantity, '0'), '.');

        return str_replace('.', ',', $q);
    }
}
