<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\LocationType;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'client_id', 'case_id', 'lawyer_id', 'starts_at', 'ends_at', 'type',
        'location_type', 'location_detail', 'subject', 'internal_notes', 'status',
        'ics_sequence', 'reminder_sent_at', 'short_reminder_sent_at', 'invoice_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'type' => AppointmentType::class,
            'location_type' => LocationType::class,
            'status' => AppointmentStatus::class,
            'ics_sequence' => 'integer',
            'reminder_sent_at' => 'datetime',
            'short_reminder_sent_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function lawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lawyer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** RDV-05 : une consultation honorée, pas encore facturée. */
    public function isBillableConsultation(): bool
    {
        return $this->type === AppointmentType::Consultation
            && $this->status === AppointmentStatus::Honored
            && $this->invoice_id === null;
    }

    /** Lieu affiché au client : « Tribunal · Tribunal de commerce, chambre 2 ». */
    public function locationLabel(): string
    {
        return $this->location_detail
            ? $this->location_type->label().' · '.$this->location_detail
            : $this->location_type->label();
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** Rendez-vous qui occupent le créneau [début, fin[ d'un avocat (RDV-03). */
    public function scopeOverlapping(Builder $query, int $lawyerId, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $query->where('lawyer_id', $lawyerId)
            ->whereIn('status', AppointmentStatus::slotValues())
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('appointments')
            ->logOnly(['client_id', 'case_id', 'lawyer_id', 'starts_at', 'ends_at', 'type', 'location_type', 'location_detail', 'subject', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Rendez-vous créé',
                'updated' => 'Rendez-vous modifié',
                'deleted' => 'Rendez-vous supprimé',
                default => $event,
            });
    }
}
