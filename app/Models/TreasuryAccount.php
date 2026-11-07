<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\TreasuryType;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class TreasuryAccount extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'type', 'currency', 'ledger_code', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => TreasuryType::class,
            'currency' => Currency::class,
            'is_active' => 'boolean',
        ];
    }

    public function isCash(): bool
    {
        return $this->type === TreasuryType::Cash;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('cash')
            ->logOnly(['name', 'type', 'currency', 'ledger_code', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Compte de trésorerie créé',
                default => 'Compte de trésorerie modifié',
            });
    }
}
