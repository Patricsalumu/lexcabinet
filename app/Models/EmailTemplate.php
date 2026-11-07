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

    /** Variables utilisables dans les modèles (NOT-06). */
    public const VARIABLES = [
        'client', 'avocat', 'date', 'heure', 'lieu', 'objet', 'cabinet', 'tel_cabinet',
        'numero', 'montant', 'echeance', 'banque', 'mode', 'liste',
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
