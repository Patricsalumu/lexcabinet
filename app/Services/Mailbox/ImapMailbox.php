<?php

namespace App\Services\Mailbox;

use Illuminate\Support\Carbon;
use Webklex\IMAP\Facades\Client as ImapClient;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

/**
 * Accès à la boîte IMAP du cabinet (config/imap.php, variables IMAP_*).
 *
 * Lecture seule : les messages sont lus en mode « peek » (ils restent non lus), jamais
 * supprimés ni déplacés. La seule écriture est l'ajout, dans « Envoyés », d'une copie
 * des réponses écrites depuis l'application.
 */
class ImapMailbox
{
    /**
     * @param  array<string, mixed>|null  $account  compte d'un cabinet (FirmMailConfig::imapAccount) ;
     *                                              null pour la boîte de la plateforme (variables IMAP_*)
     */
    public function __construct(private readonly ?array $account = null) {}

    /** Boîte propre à un cabinet (sinon : boîte de la plateforme). */
    public function isFirmMailbox(): bool
    {
        return $this->account !== null;
    }

    public function isConfigured(): bool
    {
        if ($this->account !== null) {
            return filled($this->account['host'] ?? null) && filled($this->account['username'] ?? null);
        }

        return filled(config('imap.accounts.default.host')) && filled(config('imap.accounts.default.username'));
    }

    /** Adresse de la boîte lue (identifiant IMAP), si c'est une adresse e-mail. */
    public function address(): ?string
    {
        $username = (string) ($this->account['username'] ?? config('imap.accounts.default.username'));

        return filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : null;
    }

    /**
     * Messages reçus depuis $since. Seuls les en-têtes sont lus d'abord ; le corps et les pièces
     * jointes ne sont téléchargés que pour les messages retenus par $wanted (réponses à nos e-mails).
     *
     * @param  callable(string $messageId, list<string> $citedIds): bool  $wanted
     * @return list<InboundEmail>
     */
    public function fetchSince(Carbon $since, callable $wanted): array
    {
        $client = $this->connect();
        $found = [];

        try {
            foreach ($this->account['folders'] ?? config('lexcabinet.replies.folders', ['INBOX']) as $path) {
                $folder = $client->getFolderByPath($path);
                if ($folder === null) {
                    continue;
                }

                $headers = $folder->query()->whereSince($since)->leaveUnread()->setFetchBody(false)->setFetchFlags(false)->get();

                foreach ($headers as $header) {
                    $messageId = self::single($header, 'message_id');
                    $cited = self::cited($header);
                    if ($messageId === '' || $cited === [] || ! $wanted($messageId, $cited)) {
                        continue;
                    }

                    $message = $folder->query()->leaveUnread()->setFetchFlags(false)->getMessageByUid($header->getUid());
                    $found[] = $this->toInbound($message, $messageId, $path);
                }
            }
        } finally {
            $client->disconnect();
        }

        return $found;
    }

    /** Dépose une copie (déjà lue) d'un message envoyé dans le dossier « Envoyés ». */
    public function appendToSent(string $rawMessage): void
    {
        $sent = (string) ($this->account['sent_folder'] ?? config('lexcabinet.replies.sent_folder'));
        if ($sent === '') {
            return;
        }

        $client = $this->connect();
        try {
            $client->getFolderByPath($sent)?->appendMessage($rawMessage, ['\\Seen'], now());
        } finally {
            $client->disconnect();
        }
    }

    /** Liste des dossiers de la boîte (diagnostic). @return list<string> */
    public function folders(): array
    {
        $client = $this->connect();
        try {
            return $client->getFolders(false)->map(fn ($f) => $f->path)->values()->all();
        } finally {
            $client->disconnect();
        }
    }

    protected function connect(): Client
    {
        if ($this->account !== null) {
            $account = array_diff_key($this->account, array_flip(['folders', 'sent_folder']));

            return ImapClient::make(array_merge((array) config('imap.accounts.default'), $account))->connect();
        }

        return ImapClient::account('default')->connect();
    }

    private function toInbound(Message $message, string $messageId, string $folder): InboundEmail
    {
        $from = $message->getFrom()?->first();
        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            if ($attachment->getDisposition() === 'inline' && str_starts_with((string) $attachment->getMimeType(), 'image/')) {
                continue;   // images de signature
            }
            $attachments[] = [
                'name' => (string) ($attachment->getName() ?: 'piece-jointe'),
                'content' => (string) $attachment->getContent(),
                'mime' => $attachment->getMimeType(),
            ];
        }

        $date = $message->getDate()?->first();

        return new InboundEmail(
            messageId: $messageId,
            inReplyTo: self::single($message, 'in_reply_to') ?: null,
            references: self::list($message, 'references'),
            fromEmail: strtolower((string) ($from->mail ?? '')),
            fromName: ($from->personal ?? '') !== '' ? $from->personal : null,
            subject: self::single($message, 'subject') ?: '(sans objet)',
            text: $message->getTextBody(),
            html: $message->getHTMLBody(),
            date: $date ? Carbon::parse($date) : now(),
            folder: $folder,
            attachments: $attachments,
        );
    }

    /** @return list<string> */
    private static function cited(Message $message): array
    {
        return array_values(array_filter([self::single($message, 'in_reply_to'), ...self::list($message, 'references')]));
    }

    private static function single(Message $message, string $name): string
    {
        $value = $message->get($name);
        $value = is_object($value) && method_exists($value, 'first') ? $value->first() : $value;

        return trim(str_replace(['<', '>'], '', (string) (is_array($value) ? reset($value) : $value)));
    }

    /** @return list<string> */
    private static function list(Message $message, string $name): array
    {
        $value = $message->get($name);
        $values = is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : (array) $value;
        $flat = [];
        array_walk_recursive($values, function ($v) use (&$flat) {
            foreach (preg_split('/\s+/', (string) $v) ?: [] as $id) {
                $id = trim(str_replace(['<', '>'], '', $id));
                if ($id !== '') {
                    $flat[] = $id;
                }
            }
        });

        return array_values(array_unique($flat));
    }
}
