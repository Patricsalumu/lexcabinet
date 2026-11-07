<?php

namespace App\Support;

use App\Enums\Currency;
use InvalidArgumentException;

/**
 * Montants manipulés comme chaînes décimales avec bcmath (jamais en float).
 * Affichage : « 1 250,00 $ » et « 3 500 000 FC » (espaces insécables).
 */
final class Money
{
    public const SCALE = 2;

    /** Espace insécable utilisé comme séparateur de milliers et devant le symbole. */
    public const NBSP = "\u{00A0}";

    /** Normalise un montant (chaîne, entier) en chaîne décimale à 2 décimales. */
    public static function normalize(string|int $amount, int $scale = self::SCALE): string
    {
        $amount = trim((string) $amount);
        $amount = str_replace([' ', self::NBSP, ','], ['', '', '.'], $amount);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException("Montant invalide : {$amount}");
        }

        return self::round($amount, $scale);
    }

    /** Arrondi au plus proche, demi vers l'extérieur (0,005 → 0,01). */
    public static function round(string $amount, int $scale = self::SCALE): string
    {
        $offset = '0.'.str_repeat('0', $scale).'5';
        $rounded = str_starts_with($amount, '-')
            ? bcsub($amount, $offset, $scale)
            : bcadd($amount, $offset, $scale);

        // Évite « -0.00 ».
        return bccomp($rounded, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $rounded;
    }

    public static function format(string|int $amount, Currency|string $currency): string
    {
        $currency = $currency instanceof Currency ? $currency : Currency::from($currency);
        $decimals = $currency->displayDecimals();
        $value = self::round(self::normalize($amount, 6), $decimals);

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');

        $number = ($negative ? '-' : '').self::group($int).($decimals > 0 ? ','.$frac : '');

        return $number.self::NBSP.$currency->symbol();
    }

    /** Formate un taux de change : « 2 800 FC pour 1 $ » (sans décimales inutiles). */
    public static function formatRate(string $rate): string
    {
        $value = self::normalize($rate, 4);
        [$int, $frac] = explode('.', $value);
        $frac = rtrim($frac, '0');

        return self::group(ltrim($int, '-')).($frac !== '' ? ','.$frac : '');
    }

    /** Regroupe les chiffres par milliers : 1234567 → « 1 234 567 ». */
    private static function group(string $digits): string
    {
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', self::NBSP, $digits);
    }
}
