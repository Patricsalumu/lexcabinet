<?php

namespace App\Services\Mailbox;

use Illuminate\Support\Carbon;

/**
 * Message lu dans la boîte aux lettres, indépendant de la bibliothèque IMAP.
 */
final class InboundEmail
{
    /**
     * @param  list<string>  $references
     * @param  list<array{name: string, content: string, mime: ?string}>  $attachments
     */
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
        public readonly Carbon $date,
        public readonly string $folder = 'INBOX',
        public readonly array $attachments = [],
    ) {}

    /** Identifiants cités par le message, le plus proche d'abord (In-Reply-To, puis References). */
    public function citedIds(): array
    {
        return array_values(array_unique(array_filter([$this->inReplyTo, ...array_reverse($this->references)])));
    }
}
