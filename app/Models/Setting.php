<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paramètre du cabinet (clé / valeur typée). Lecture et écriture via SettingsService.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'updated_by'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
