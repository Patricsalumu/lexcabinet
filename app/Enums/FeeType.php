<?php

namespace App\Enums;

/** Convention d'honoraires (DOS-04, FAC-01 à FAC-03). */
enum FeeType: string
{
    case Flat = 'flat';
    case Hourly = 'hourly';
    case Subscription = 'subscription';
    case RetainerSuccess = 'retainer_success';

    public function label(): string
    {
        return match ($this) {
            self::Flat => 'Forfait',
            self::Hourly => 'Taux horaire',
            self::Subscription => 'Abonnement mensuel',
            self::RetainerSuccess => 'Provision + résultat',
        };
    }

    /** Sens du montant de la convention. */
    public function amountLabel(): string
    {
        return match ($this) {
            self::Flat => 'Prix total de l\'affaire',
            self::Hourly => 'Prix d\'une heure',
            self::Subscription => 'Prix d\'une période de 30 jours',
            self::RetainerSuccess => 'Provision demandée',
        };
    }

    public function amountSuffix(): string
    {
        return match ($this) {
            self::Hourly => ' / h',
            self::Subscription => ' / 30 jours',
            default => '',
        };
    }
}
