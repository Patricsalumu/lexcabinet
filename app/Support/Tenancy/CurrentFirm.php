<?php

namespace App\Support\Tenancy;

use App\Models\Firm;
use LogicException;

/**
 * Cabinet courant : celui de l'utilisateur connecté, du job en cours de traitement,
 * ou celui qu'une commande planifiée traite. Toutes les requêtes sur les données métier
 * sont filtrées sur ce cabinet (FirmScope) ; sans cabinet courant, elles ne renvoient rien.
 */
final class CurrentFirm
{
    private ?int $id = null;

    private ?Firm $firm = null;

    public function id(): ?int
    {
        return $this->id;
    }

    public function has(): bool
    {
        return $this->id !== null;
    }

    public function firm(): ?Firm
    {
        if ($this->id === null) {
            return null;
        }

        return $this->firm ??= Firm::query()->find($this->id);
    }

    public function require(): int
    {
        return $this->id ?? throw new LogicException('Aucun cabinet courant : opération impossible hors du contexte d\'un cabinet.');
    }

    public function set(Firm|int|null $firm): void
    {
        $this->id = $firm instanceof Firm ? $firm->id : $firm;
        $this->firm = $firm instanceof Firm ? $firm : null;
    }

    public function forget(): void
    {
        $this->set(null);
    }

    /**
     * Exécute $callback dans le contexte d'un cabinet, puis rétablit le contexte précédent.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Firm|int $firm, callable $callback): mixed
    {
        [$previousId, $previousFirm] = [$this->id, $this->firm];
        $this->set($firm);

        try {
            return $callback();
        } finally {
            [$this->id, $this->firm] = [$previousId, $previousFirm];
        }
    }
}
