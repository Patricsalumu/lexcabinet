<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Cabinet utilisateur de la plateforme. Toutes les données métier lui appartiennent (firm_id) ;
 * un utilisateur n'appartient qu'à un seul cabinet.
 */
class Firm extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('platform')
            ->logOnly(['name', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
