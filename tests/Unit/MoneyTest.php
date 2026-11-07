<?php

use App\Enums\Currency;
use App\Support\Money;

/** Remplace les espaces insécables par des espaces pour la lisibilité des attentes. */
function plain(string $value): string
{
    return str_replace(Money::NBSP, ' ', $value);
}

it('formate les montants en USD avec deux décimales', function () {
    expect(plain(Money::format('1250', Currency::USD)))->toBe('1 250,00 $')
        ->and(plain(Money::format('1250.5', 'USD')))->toBe('1 250,50 $')
        ->and(plain(Money::format('0', Currency::USD)))->toBe('0,00 $')
        ->and(plain(Money::format('999.995', Currency::USD)))->toBe('1 000,00 $')
        ->and(plain(Money::format('-1250', Currency::USD)))->toBe('-1 250,00 $')
        ->and(plain(Money::format('1234567890123.45', Currency::USD)))->toBe('1 234 567 890 123,45 $');
});

it('formate les montants en CDF sans décimales', function () {
    expect(plain(Money::format('3500000', Currency::CDF)))->toBe('3 500 000 FC')
        ->and(plain(Money::format('2800000.50', Currency::CDF)))->toBe('2 800 001 FC')
        ->and(plain(Money::format('700', Currency::CDF)))->toBe('700 FC');
});

it('utilise des espaces insécables', function () {
    expect(Money::format('1250', Currency::USD))->toBe("1\u{00A0}250,00\u{00A0}$");
});

it('normalise et arrondit sans passer par des flottants', function () {
    expect(Money::normalize('1 250,5'))->toBe('1250.50')
        ->and(Money::normalize('0.105'))->toBe('0.11')
        ->and(Money::normalize('-0.004'))->toBe('0.00')
        ->and(Money::normalize(42))->toBe('42.00');
});

it('refuse un montant invalide', function () {
    Money::normalize('12a');
})->throws(InvalidArgumentException::class);

it('formate un taux de change', function () {
    expect(plain(Money::formatRate('2800.0000')))->toBe('2 800')
        ->and(plain(Money::formatRate('2795.5')))->toBe('2 795,5');
});
