<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use App\Support\QuotedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Réponse reçue à un e-mail envoyé par l'application, relevée dans la boîte IMAP du cabinet.
 * C'est une copie : le message d'origine reste dans la boîte, non lu, non déplacé.
 */
class EmailReply extends Model
{
    use BelongsToFirm;

    protected $fillable = [
        'email_log_id', 'thread_id', 'message_id', 'in_reply_to', 'references', 'from_email', 'from_name',
        'sender_type', 'sender_id', 'subject', 'body', 'attachments', 'folder', 'received_at', 'read_at', 'read_by',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'received_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class, 'thread_id');
    }

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    public function readBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by');
    }

    /** Texte de la réponse sans la citation du message précédent. */
    public function excerpt(): string
    {
        return QuotedText::strip($this->body);
    }

    /** Identifiants des messages précédents, du plus ancien au plus récent. */
    public function referenceIds(): array
    {
        return array_values(array_filter(preg_split('/\s+/', (string) $this->references) ?: []));
    }
}
