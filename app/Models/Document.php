<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Pièce jointe d'un dossier, stockée sur le disque privé et servie après contrôle des droits.
 */
class Document extends Model
{
    use LogsActivity;

    protected $fillable = ['case_id', 'category', 'original_name', 'path', 'mime_type', 'size', 'uploaded_by'];

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'size' => 'integer',
        ];
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', ' ').' Mo'
            : max(1, (int) round($this->size / 1024)).' Ko';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('documents')
            ->logOnly(['case_id', 'category', 'original_name', 'size'])
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Pièce jointe ajoutée',
                'deleted' => 'Pièce jointe supprimée',
                default => 'Pièce jointe modifiée',
            });
    }
}
