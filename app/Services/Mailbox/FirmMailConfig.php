<?php

namespace App\Services\Mailbox;

use App\Services\SettingsService;
use App\Support\Tenancy\CurrentFirm;

/**
 * Messagerie du cabinet courant (phase M3), d'après ses paramètres :
 * - envoi par son propre serveur SMTP s'il est configuré, sinon par celui de la plateforme ;
 * - lecture des réponses dans sa propre boîte IMAP si elle est configurée, sinon dans celle
 *   de la plateforme (variables IMAP_* du .env).
 */
class FirmMailConfig
{
    /** Transports de développement : rien n'est délivré, le SMTP du cabinet n'est pas utilisé. */
    private const NON_DELIVERING = ['log', 'array'];

    public function __construct(private readonly SettingsService $settings, private readonly CurrentFirm $current) {}

    public function smtpConfigured(): bool
    {
        return (bool) $this->settings->get('mail.smtp_enabled')
            && filled($this->settings->get('mail.smtp_host'))
            && filled($this->settings->get('mail.smtp_username'))
            && $this->settings->hasSecret('mail.smtp_password');
    }

    /** Les e-mails du cabinet partent-ils par son SMTP ? (jamais avec un transport de développement) */
    public function usesOwnSmtp(): bool
    {
        return $this->smtpConfigured() && ! in_array(config('mail.default'), self::NON_DELIVERING, true);
    }

    public function mailerName(): string
    {
        return 'firm_'.$this->current->require();
    }

    /** Déclare le transport SMTP du cabinet auprès de Laravel et renvoie son nom. */
    public function registerMailer(): string
    {
        $name = $this->mailerName();
        $encryption = (string) $this->settings->get('mail.smtp_encryption');

        config(["mail.mailers.{$name}" => [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => (string) $this->settings->get('mail.smtp_host'),
            'port' => (int) $this->settings->get('mail.smtp_port'),
            'username' => (string) $this->settings->get('mail.smtp_username'),
            'password' => (string) $this->settings->get('mail.smtp_password'),
            'timeout' => 30,
            'local_domain' => config('mail.mailers.smtp.local_domain'),
        ]]);
        app('mail.manager')->purge($name);   // la configuration a pu changer depuis le dernier envoi

        return $name;
    }

    /** Adresse d'expédition : celle du compte SMTP du cabinet, sinon celle de la plateforme. */
    public function fromAddress(): string
    {
        if ($this->usesOwnSmtp()) {
            return (string) ($this->settings->get('mail.from_address') ?: $this->settings->get('mail.smtp_username'));
        }

        return (string) config('mail.from.address');
    }

    public function imapConfigured(): bool
    {
        return (bool) $this->settings->get('mail.imap_enabled')
            && filled($this->settings->get('mail.imap_host'))
            && filled($this->settings->get('mail.imap_username'))
            && $this->settings->hasSecret('mail.imap_password');
    }

    /** @return array<string, mixed> compte IMAP du cabinet, au format de webklex/php-imap */
    public function imapAccount(): array
    {
        return [
            'host' => (string) $this->settings->get('mail.imap_host'),
            'port' => (int) $this->settings->get('mail.imap_port'),
            'encryption' => (string) $this->settings->get('mail.imap_encryption') ?: false,
            'validate_cert' => true,
            'username' => (string) $this->settings->get('mail.imap_username'),
            'password' => (string) $this->settings->get('mail.imap_password'),
            'protocol' => 'imap',
            'folders' => array_values(array_filter(array_map('trim', explode(',', (string) $this->settings->get('mail.imap_folders'))))) ?: ['INBOX'],
            'sent_folder' => (string) $this->settings->get('mail.imap_sent_folder'),
        ];
    }

    /** Boîte du cabinet si elle est configurée, sinon null. */
    public function ownMailbox(): ?ImapMailbox
    {
        return $this->imapConfigured() ? app()->makeWith(ImapMailbox::class, ['account' => $this->imapAccount()]) : null;
    }

    /** Boîte où arrivent les réponses du cabinet : la sienne, sinon celle de la plateforme. */
    public function replyMailbox(): ImapMailbox
    {
        return $this->ownMailbox() ?? app(ImapMailbox::class);
    }
}
