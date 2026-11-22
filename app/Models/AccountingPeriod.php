<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AccountingPeriod extends Model
{
    use BelongsToFirm;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $fillable = ['year', 'month', 'status', 'closed_at', 'closed_by'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'closed_at' => 'datetime'];
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    public function start(): Carbon
    {
        return Carbon::create($this->year, $this->month, 1)->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->start()->endOfMonth();
    }

    public function label(): string
    {
        return ucfirst($this->start()->translatedFormat('F Y'));
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
