<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class EmailTemplate extends Model
{
    use LogsActivity;

    public const CONFIRMATION = 'confirmation';

    public const REMINDER = 'reminder';

    public const REMINDER_SOON = 'reminder_soon';

    public const CANCELLATION = 'cancellation';

    public const POSTPONEMENT = 'postponement';

    public const INVOICE = 'invoice';

    public const RECEIPT = 'receipt';

    public const RECAP = 'recap';

    public const APPOINTMENT_UPDATED = 'appointment_updated';

    public const APPOINTMENT_LAWYER = 'appointment_lawyer';

    public const APPOINTMENT_LAWYER_UPDATED = 'appointment_lawyer_updated';

    public const APPOINTMENT_LAWYER_CANCELLED = 'appointment_lawyer_cancelled';

    /** Modèles d'un rendez-vous réservés à l'avocat qui en est chargé : pas de relance vers un autre. */
    public const APPOINTMENT_LAWYER_TEMPLATES = [self::APPOINTMENT_LAWYER, self::APPOINTMENT_LAWYER_UPDATED];

    public const ACCOUNT_CREATED = 'account_created';

    public const CASE_CREATED_ADMIN = 'case_created_admin';

    public const CASE_CREATED_CLIENT = 'case_created_client';

    public const CASE_CREATED_LAWYER = 'case_created_lawyer';

    public const CASE_UPDATED_CLIENT = 'case_updated_client';

    public const CASE_UPDATED_LAWYER = 'case_updated_lawyer';

    public const CASE_LAWYER_ADDED = 'case_lawyer_added';

    public const CASE_LAWYER_REMOVED = 'case_lawyer_removed';

    /** Modèles réservés aux avocats associés au dossier : jamais relancés vers un avocat retiré. */
    public const CASE_LAWYER_TEMPLATES = [self::CASE_CREATED_LAWYER, self::CASE_UPDATED_LAWYER, self::CASE_LAWYER_ADDED];

    /** Variables utilisables dans les modèles (NOT-06). */
    public const VARIABLES = [
        'client', 'avocat', 'date', 'heure', 'lieu', 'objet', 'cabinet', 'tel_cabinet', 'email_cabinet',
        'adresse_cabinet', 'coordonnees_cabinet', 'lien_application', 'numero', 'montant', 'devise',
        'echeance', 'banque', 'mode', 'liste', 'destinataire', 'email', 'roles', 'lien', 'validite',
        'reference', 'dossier', 'avocats', 'qualite', 'createur', 'prise_en_charge', 'modifications',
        'type', 'contact_client', 'notes', 'evenement',
    ];

    protected $fillable = ['key', 'name', 'subject', 'body', 'updated_by'];

    /**
     * Remplace les variables {nom} par leur valeur ; une variable inconnue reste telle quelle.
     *
     * @param  array<string, string|null>  $vars
     */
    public static function render(string $text, array $vars): string
    {
        return preg_replace_callback('/\{(\w+)\}/u', fn (array $m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $text);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('email_templates')
            ->logOnly(['subject', 'body'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => $event === 'updated' ? 'Modèle d\'e-mail modifié' : 'Modèle d\'e-mail '.$event);
    }
}
