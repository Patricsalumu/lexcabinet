<?php

namespace App\Models;

use App\Enums\CaseEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CaseEvent extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['case_id', 'type', 'occurred_at', 'description', 'chamber', 'created_by'];

    protected function casts(): array
    {
        return [
            'type' => CaseEventType::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('cases')
            ->logOnly(['case_id', 'type', 'occurred_at', 'description', 'chamber'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Événement ajouté au journal du dossier',
                'updated' => 'Événement du journal modifié',
                'deleted' => 'Événement du journal supprimé',
                default => $event,
            });
    }
}
