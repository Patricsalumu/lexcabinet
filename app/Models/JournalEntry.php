<?php

namespace App\Models;

use App\Enums\Journal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Écriture comptable : jamais modifiée ni supprimée ; corrigée par contre-passation.
 */
class JournalEntry extends Model
{
    protected $fillable = [
        'number', 'journal', 'entry_date', 'label', 'reference', 'source_type', 'source_id',
        'source_event', 'reverses_id', 'is_manual', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'journal' => Journal::class,
            'entry_date' => 'date',
            'is_manual' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une écriture comptable ne se modifie pas : contre-passez-la.'));
        static::deleting(fn () => throw new LogicException('Une écriture comptable ne se supprime pas : contre-passez-la.'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
