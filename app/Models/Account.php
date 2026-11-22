<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Compte du plan comptable. Modifiable (libellé, activation) ; jamais supprimé s'il est mouvementé.
 */
class Account extends Model
{
    use BelongsToFirm;

    use LogsActivity;

    protected $fillable = ['code', 'label', 'class', 'is_active'];

    protected function casts(): array
    {
        return ['class' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (Account $account) {
            if ($account->isDirty('code') && $account->lines()->exists()) {
                throw new LogicException('Le numéro d\'un compte mouvementé ne peut pas changer.');
            }
        });

        static::deleting(function (Account $account) {
            if ($account->lines()->exists()) {
                throw new LogicException('Un compte mouvementé ne peut pas être supprimé.');
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('accounting')
            ->logOnly(['code', 'label', 'class', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Compte comptable créé',
                'updated' => 'Compte comptable modifié',
                default => 'Compte comptable '.$event,
            });
    }
}
