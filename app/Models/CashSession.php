<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    use BelongsToFirm;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $fillable = [
        'status', 'opened_at', 'opened_by', 'opening_usd', 'opening_cdf', 'book_opening_usd', 'book_opening_cdf',
        'closed_at', 'closed_by', 'expected_usd', 'expected_cdf', 'counted_usd', 'counted_cdf',
        'difference_usd', 'difference_cdf', 'difference_reason',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_usd' => 'decimal:2',
            'opening_cdf' => 'decimal:2',
            'book_opening_usd' => 'decimal:2',
            'book_opening_cdf' => 'decimal:2',
            'expected_usd' => 'decimal:2',
            'expected_cdf' => 'decimal:2',
            'counted_usd' => 'decimal:2',
            'counted_cdf' => 'decimal:2',
            'difference_usd' => 'decimal:2',
            'difference_cdf' => 'decimal:2',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
