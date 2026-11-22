<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use App\Enums\RateSource;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Taux de change journalier : nombre de CDF pour 1 USD.
 */
class ExchangeRate extends Model
{
    use BelongsToFirm;

    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['rate_date', 'rate', 'source', 'created_by'];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'decimal:4',
            'source' => RateSource::class,
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('exchange_rates')
            ->logOnly(['rate_date', 'rate', 'source'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Taux de change enregistré',
                'updated' => 'Taux de change modifié',
                'deleted' => 'Taux de change supprimé',
                default => $event,
            });
    }
}
