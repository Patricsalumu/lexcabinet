<?php

namespace App\Models;

use App\Enums\EmailStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Journal d'envoi des e-mails (NOT-07).
 */
class EmailLog extends Model
{
    protected $fillable = [
        'template_key', 'message_id', 'thread_id', 'in_reply_to', 'references', 'recipient_name', 'recipient_email', 'subject', 'body', 'attachments',
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

    /**
     * Chaque e-mail reçoit son propre Message-ID, sur le domaine de l'expéditeur : les réponses
     * le citent (In-Reply-To, References), ce qui permet de les rattacher à la conversation.
     */
    protected static function booted(): void
    {
        static::creating(function (EmailLog $log) {
            $log->message_id ??= self::newMessageId();
        });
    }

    public static function newMessageId(): string
    {
        $domain = Str::after((string) config('mail.from.address'), '@');

        return 'lc.'.Str::lower((string) Str::ulid()).'@'.(filled($domain) && str_contains($domain, '.') ? $domain : 'lexcabinet.local');
    }

    /** Premier e-mail de la conversation (lui-même s'il l'ouvre). */
    public function threadRoot(): EmailLog
    {
        return $this->thread_id ? ($this->thread ?? $this) : $this;
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class, 'thread_id');
    }

    /** Réponses envoyées depuis l'application dans cette conversation. */
    public function threadMessages(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'thread_id');
    }

    /** Réponses reçues dans cette conversation. */
    public function threadReplies(): HasMany
    {
        return $this->hasMany(EmailReply::class, 'thread_id');
    }

    /** @return list<string> */
    public function referenceIds(): array
    {
        return array_values(array_filter(preg_split('/\s+/', (string) $this->references) ?: []));
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
