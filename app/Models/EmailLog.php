<?php

namespace App\Models;

use App\Enums\EmailStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Journal d'envoi des e-mails (NOT-07).
 */
class EmailLog extends Model
{
    protected $fillable = [
        'template_key', 'recipient_name', 'recipient_email', 'subject', 'body', 'attachments',
        'status', 'reason', 'attempts', 'recipient_type', 'recipient_id', 'related_type', 'related_id',
        'triggered_by', 'resent_from_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'status' => EmailStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_key', 'key');
    }

    /** @return list<string> Noms des pièces jointes, pour l'affichage. */
    public function attachmentNames(): array
    {
        return array_map(fn (array $a) => $a['name'] ?? $a['type'], $this->attachments ?? []);
    }
}
