<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Temps passé (DOS-05). « Facturé » = invoice_id renseigné à l'émission de la facture.
 */
class TimeEntry extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['case_id', 'user_id', 'worked_on', 'minutes', 'description', 'billable', 'invoice_id'];

    protected function casts(): array
    {
        return [
            'worked_on' => 'date',
            'minutes' => 'integer',
            'billable' => 'boolean',
        ];
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isInvoiced(): bool
    {
        return $this->invoice_id !== null;
    }

    /** Durée en heures, affichée à la française : « 2,5 h ». */
    public function hoursLabel(): string
    {
        return self::formatMinutes($this->minutes);
    }

    public static function formatMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? "{$hours} h" : sprintf('%d h %02d', $hours, $rest);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('time_entries')
            ->logOnly(['case_id', 'user_id', 'worked_on', 'minutes', 'description', 'billable', 'invoice_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Temps saisi',
                'updated' => 'Temps modifié',
                'deleted' => 'Temps supprimé',
                default => $event,
            });
    }
}
