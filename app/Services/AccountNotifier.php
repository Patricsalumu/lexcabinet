<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * E-mail d'accès d'un nouvel utilisateur : lien sécurisé pour définir son mot de passe.
 *
 * Aucun mot de passe n'est envoyé. Le lien est signé, valable LINK_HOURS heures, et lié à
 * l'empreinte du mot de passe actuel : il cesse de fonctionner dès que l'utilisateur a défini
 * son mot de passe (usage unique de fait).
 */
class AccountNotifier
{
    public const LINK_HOURS = 72;

    public function __construct(private readonly EmailService $emails) {}

    public function sendAccess(User $user, ?User $by = null): EmailLog
    {
        return $this->emails->send(EmailTemplate::ACCOUNT_CREATED, $user, [
            'destinataire' => $user->name,
            'email' => $user->email,
            'roles' => implode(', ', $user->roleLabels()) ?: '—',
            'lien' => $this->accessUrl($user),
            'validite' => self::LINK_HOURS.' heures',
        ], $user, [], $by);
    }

    public function accessUrl(User $user): string
    {
        return URL::temporarySignedRoute('account.activate', now()->addHours(self::LINK_HOURS), [
            'user' => $user->id,
            'h' => self::fingerprint($user),
        ]);
    }

    /** Empreinte courte du mot de passe haché : change dès que le mot de passe change. */
    public static function fingerprint(User $user): string
    {
        return substr(hash('sha256', (string) $user->getAuthPassword()), 0, 16);
    }
}
