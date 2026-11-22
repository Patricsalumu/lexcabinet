<?php

namespace App\Services;

use App\Models\Client;
use App\Models\EmailLog;
use App\Models\User;

/**
 * Partage d'un e-mail du journal sur WhatsApp, vers le téléphone de son destinataire :
 * la fiche client pour un client, le profil de l'utilisateur pour un avocat ou un administrateur.
 *
 * Le lien wa.me ouvre WhatsApp avec le message pré-rempli ; l'utilisateur l'envoie lui-même.
 */
class WhatsAppShare
{
    /** Indicatif ajouté aux numéros locaux (RDC). */
    private const DEFAULT_COUNTRY_CODE = '243';

    public function __construct(private readonly EmailService $emails) {}

    /** Motif empêchant le partage, ou null si le partage est possible. */
    public function refusalReason(EmailLog $log): ?string
    {
        $recipient = $log->recipient;
        if (! $recipient instanceof Client && ! $recipient instanceof User) {
            return 'Destinataire introuvable.';
        }

        if ($recipient instanceof Client && ! $recipient->email_consent) {
            return 'Le client n\'a pas accepté les notifications.';
        }

        if ($recipient instanceof User && ! $recipient->is_active) {
            return 'Compte utilisateur désactivé.';
        }

        if ($this->phone($recipient) === null) {
            return 'Aucun numéro de téléphone valide sur la fiche du destinataire.';
        }

        return $this->emails->confidentialityReason($log, $recipient);
    }

    /** Lien wa.me vers le destinataire, avec l'objet et le texte de l'e-mail. */
    public function url(EmailLog $log): ?string
    {
        if ($this->refusalReason($log) !== null) {
            return null;
        }

        $text = '*'.$log->subject."*\n\n".$log->body;

        return 'https://wa.me/'.$this->phone($log->recipient).'?text='.rawurlencode($text);
    }

    /** Numéro au format international sans « + » (ex. 243810000000), ou null. */
    public function phone(Client|User $recipient): ?string
    {
        return self::normalize($recipient->phone);
    }

    public static function normalize(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }

        $international = str_starts_with($phone, '+') || str_starts_with($phone, '00');
        $digits = preg_replace('/\D+/', '', $phone);

        if ($international) {
            $digits = str_starts_with($phone, '00') ? substr($digits, 2) : $digits;
        } elseif (str_starts_with($digits, '0')) {
            $digits = self::DEFAULT_COUNTRY_CODE.substr($digits, 1);   // 081… → 24381…
        } elseif (strlen($digits) === 9) {
            $digits = self::DEFAULT_COUNTRY_CODE.$digits;               // 81… → 24381…
        }

        return preg_match('/^[1-9]\d{7,14}$/', $digits) ? $digits : null;
    }
}
