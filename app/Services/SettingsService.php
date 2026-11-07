<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
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
        'firm.email' => ['type' => 'string', 'default' => null, 'label' => 'E-mail d\'envoi'],
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

        // Heures d'ouverture (ADM-03)
        'office.opens_at' => ['type' => 'string', 'default' => '08:00', 'label' => 'Ouverture'],
        'office.closes_at' => ['type' => 'string', 'default' => '17:00', 'label' => 'Fermeture'],
        'office.days' => ['type' => 'json', 'default' => [1, 2, 3, 4, 5], 'label' => 'Jours d\'ouverture'],
    ];

    public function get(string $key): mixed
    {
        $this->assertKnown($key);
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : self::DEFINITIONS[$key]['default'];
    }

    /** @return array<string, mixed> Tous les paramètres, valeurs par défaut comprises. */
    public function all(): array
    {
        $stored = Cache::remember(self::CACHE_KEY, config('lexcabinet.settings_cache_ttl'), function () {
            return Setting::query()->get(['key', 'value', 'type'])
                ->mapWithKeys(fn (Setting $s) => [$s->key => $this->cast($s->value, $s->type)])
                ->all();
        });

        $values = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $values[$key] = array_key_exists($key, $stored) ? $stored[$key] : $definition['default'];
        }

        return $values;
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
        Cache::forget(self::CACHE_KEY);
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
