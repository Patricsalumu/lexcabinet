<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use App\Support\Tenancy\CurrentFirm;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Paramètres du cabinet (ADM-01, ADM-03), stockés en clé / valeur typée.
 * Toute modification est tracée dans le journal d'audit.
 */
class SettingsService
{
    private const CACHE_KEY = 'lexcabinet.settings';

    /**
     * Définition des paramètres : type, valeur par défaut et libellé (pour l'audit).
     *
     * @var array<string, array{type: string, default: mixed, label: string}>
     */
    public const DEFINITIONS = [
        // Identité du cabinet (ADM-01)
        'firm.name' => ['type' => 'string', 'default' => 'Cabinet', 'label' => 'Nom du cabinet'],
        'firm.bar' => ['type' => 'string', 'default' => null, 'label' => 'Barreau / n° d\'inscription'],
        'firm.address' => ['type' => 'string', 'default' => null, 'label' => 'Adresse'],
        'firm.rccm' => ['type' => 'string', 'default' => null, 'label' => 'RCCM'],
        'firm.id_nat' => ['type' => 'string', 'default' => null, 'label' => 'ID. Nat.'],
        'firm.nif' => ['type' => 'string', 'default' => null, 'label' => 'NIF'],
        'firm.phone' => ['type' => 'string', 'default' => null, 'label' => 'Téléphone'],
        'firm.email' => ['type' => 'string', 'default' => null, 'label' => 'E-mail du cabinet (adresse de réponse)'],
        'firm.bank_details' => ['type' => 'string', 'default' => null, 'label' => 'Coordonnées bancaires'],
        'firm.logo_path' => ['type' => 'string', 'default' => null, 'label' => 'Logo'],

        // Taxes (FAC-08)
        'vat.enabled' => ['type' => 'bool', 'default' => false, 'label' => 'TVA activée'],
        'vat.rate' => ['type' => 'decimal', 'default' => '16.00', 'label' => 'Taux de TVA (%)'],

        // Caisse (CAI-05) : seuil en USD, équivalent CDF au taux du jour
        'cash.disbursement_threshold_usd' => ['type' => 'decimal', 'default' => '500.00', 'label' => 'Seuil de validation des décaissements (USD)'],

        // Facturation
        'consultation.default_fee' => ['type' => 'decimal', 'default' => '100.00', 'label' => 'Tarif de consultation par défaut'],
        'consultation.default_currency' => ['type' => 'string', 'default' => 'USD', 'label' => 'Devise de la consultation'],
        'subscription_auto_issue' => ['type' => 'bool', 'default' => false, 'label' => 'Émission automatique des factures d\'abonnement'],

        // Notifications (NOT-02, NOT-04)
        'reminders.day_before_time' => ['type' => 'string', 'default' => '18:00', 'label' => 'Heure du rappel J-1'],
        'reminders.hours_before' => ['type' => 'int', 'default' => 2, 'label' => 'Rappel avant le RDV (heures)'],
        'recap.time' => ['type' => 'string', 'default' => '07:00', 'label' => 'Heure du récapitulatif des avocats'],

        // Messagerie du cabinet (phase M3). Sans configuration, la plateforme envoie pour le cabinet
        // (son nom en expéditeur, son e-mail en adresse de réponse). Les mots de passe sont chiffrés.
        'mail.smtp_enabled' => ['type' => 'bool', 'default' => false, 'label' => 'Envoi par le serveur SMTP du cabinet'],
        'mail.smtp_host' => ['type' => 'string', 'default' => null, 'label' => 'Serveur SMTP'],
        'mail.smtp_port' => ['type' => 'int', 'default' => 465, 'label' => 'Port SMTP'],
        'mail.smtp_encryption' => ['type' => 'string', 'default' => 'ssl', 'label' => 'Chiffrement SMTP'],
        'mail.smtp_username' => ['type' => 'string', 'default' => null, 'label' => 'Identifiant SMTP'],
        'mail.smtp_password' => ['type' => 'secret', 'default' => null, 'label' => 'Mot de passe SMTP'],
        'mail.from_address' => ['type' => 'string', 'default' => null, 'label' => 'Adresse d\'expédition'],
        'mail.imap_enabled' => ['type' => 'bool', 'default' => false, 'label' => 'Lecture des réponses dans la boîte du cabinet'],
        'mail.imap_host' => ['type' => 'string', 'default' => null, 'label' => 'Serveur IMAP'],
        'mail.imap_port' => ['type' => 'int', 'default' => 993, 'label' => 'Port IMAP'],
        'mail.imap_encryption' => ['type' => 'string', 'default' => 'ssl', 'label' => 'Chiffrement IMAP'],
        'mail.imap_username' => ['type' => 'string', 'default' => null, 'label' => 'Identifiant IMAP'],
        'mail.imap_password' => ['type' => 'secret', 'default' => null, 'label' => 'Mot de passe IMAP'],
        'mail.imap_folders' => ['type' => 'string', 'default' => 'INBOX', 'label' => 'Dossiers relevés'],
        'mail.imap_sent_folder' => ['type' => 'string', 'default' => 'Sent', 'label' => 'Dossier des messages envoyés'],

        // Heures d'ouverture (ADM-03)
        'office.opens_at' => ['type' => 'string', 'default' => '08:00', 'label' => 'Ouverture'],
        'office.closes_at' => ['type' => 'string', 'default' => '17:00', 'label' => 'Fermeture'],
        'office.days' => ['type' => 'json', 'default' => [1, 2, 3, 4, 5], 'label' => 'Jours d\'ouverture'],
    ];

    public function get(string $key): mixed
    {
        $this->assertKnown($key);

        // Secret : stocké et mis en cache chiffré, déchiffré seulement ici, à la demande.
        if (self::DEFINITIONS[$key]['type'] === 'secret') {
            return $this->decrypt($this->stored()[$key] ?? null);
        }

        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : self::DEFINITIONS[$key]['default'];
    }

    /** Un secret est-il enregistré (sans le révéler) ? */
    public function hasSecret(string $key): bool
    {
        return filled($this->get($key));
    }

    /**
     * Tous les paramètres, valeurs par défaut comprises. Les secrets n'y figurent jamais
     * (valeur nulle) : ce tableau est transmis aux vues, PDF et e-mails.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = $this->stored();

        $values = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $values[$key] = $definition['type'] === 'secret'
                ? null
                : (array_key_exists($key, $stored) ? $stored[$key] : $definition['default']);
        }

        return $values;
    }

    /** @return array<string, mixed> valeurs enregistrées (secrets encore chiffrés), en cache par cabinet */
    private function stored(): array
    {
        return Cache::remember($this->cacheKey(), config('lexcabinet.settings_cache_ttl'), function () {
            return Setting::query()->get(['key', 'value', 'type'])
                ->mapWithKeys(fn (Setting $s) => [$s->key => $this->cast($s->value, $s->type)])
                ->all();
        });
    }

    /**
     * Enregistre plusieurs paramètres dans une transaction et trace chaque changement.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, ?User $by = null): void
    {
        foreach (array_keys($values) as $key) {
            $this->assertKnown($key);
        }

        DB::transaction(function () use ($values, $by) {
            $current = $this->all();

            foreach ($values as $key => $value) {
                $type = self::DEFINITIONS[$key]['type'];

                if ($type === 'secret') {
                    if ((string) $value === (string) $this->get($key)) {
                        continue;
                    }

                    Setting::query()->updateOrCreate(
                        ['key' => $key],
                        ['value' => filled($value) ? Crypt::encryptString((string) $value) : null, 'type' => $type, 'updated_by' => $by?->id],
                    );
                    activity('settings')->causedBy($by)->withProperties(['key' => $key])   // jamais la valeur
                        ->log('Paramètre modifié : '.self::DEFINITIONS[$key]['label']);

                    continue;
                }

                $serialized = $this->serialize($value, $type);
                $old = $this->serialize($current[$key], $type);

                if ($serialized === $old) {
                    continue;
                }

                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $serialized, 'type' => $type, 'updated_by' => $by?->id],
                );

                activity('settings')
                    ->causedBy($by)
                    ->withProperties(['key' => $key, 'old' => $old, 'new' => $serialized])
                    ->log('Paramètre modifié : '.self::DEFINITIONS[$key]['label']);
            }
        });

        $this->flush();
    }

    public function set(string $key, mixed $value, ?User $by = null): void
    {
        $this->setMany([$key => $value], $by);
    }

    public function flush(): void
    {
        Cache::forget($this->cacheKey());
    }

    /** Un cache par cabinet. */
    private function cacheKey(): string
    {
        return self::CACHE_KEY.'.'.(app(CurrentFirm::class)->id() ?? 'none');
    }

    private function decrypt(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            return null;   // clé d'application changée : le secret doit être ressaisi
        }
    }

    private function assertKnown(string $key): void
    {
        if (! array_key_exists($key, self::DEFINITIONS)) {
            throw new InvalidArgumentException("Paramètre inconnu : {$key}");
        }
    }

    private function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'bool' => $value === '1',
            'int' => (int) $value,
            'decimal' => Money::normalize($value),
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    private function serialize(mixed $value, string $type): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'int' => (string) (int) $value,
            'decimal' => Money::normalize((string) $value),
            'json' => json_encode(array_values((array) $value)),
            default => trim((string) $value),
        };
    }
}
