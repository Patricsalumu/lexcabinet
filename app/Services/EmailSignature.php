<?php

namespace App\Services;

use App\Models\TreasuryAccount;

/**
 * Signature ajoutée à la fin de chaque e-mail envoyé à un client :
 * moyens de paiement du cabinet (comptes « affichés aux clients »), puis adresse et contacts.
 */
class EmailSignature
{
    public const SEPARATOR = "\n\n—\n";

    public function __construct(private readonly SettingsService $settings) {}

    public function forClients(): string
    {
        $firm = $this->settings->all();
        $blocks = [];

        $methods = $this->paymentLines();
        if ($methods !== []) {
            $blocks[] = "Moyens de paiement :\n".implode("\n", array_map(fn ($l) => '• '.$l, $methods));
        }

        $blocks[] = implode("\n", array_filter([
            $firm['firm.name'],
            $firm['firm.address'],
            $firm['firm.phone'] ? 'Téléphone : '.$firm['firm.phone'] : null,
            $firm['firm.email'] ? 'E-mail : '.$firm['firm.email'] : null,
        ]));

        return implode("\n\n", array_filter($blocks));
    }

    /** @return list<string> une ligne par moyen de paiement communiqué aux clients */
    public function paymentLines(): array
    {
        return TreasuryAccount::query()->paymentMethods()->get()
            ->map(fn (TreasuryAccount $a) => $a->paymentLine())
            ->values()->all();
    }

    /** Ajoute la signature au corps d'un e-mail client. */
    public function append(string $body): string
    {
        return rtrim($body).self::SEPARATOR.$this->forClients();
    }
}
