<?php

namespace App\Services;

use App\Models\Client;
use App\Models\LegalCase;
use Illuminate\Support\Str;

/**
 * Contrôle des conflits d'intérêts (CLI-04) : recherche des mots du nom
 * (plus de 2 lettres) parmi les clients et les parties adverses existants.
 * La recherche ignore la casse et les accents (collation utf8mb4_unicode_ci).
 */
class ConflictCheckService
{
    /**
     * @return list<array{kind: string, label: string}>
     */
    public function matchesForName(string $name, ?int $ignoreClientId = null): array
    {
        $words = $this->words($name);
        if ($words === []) {
            return [];
        }

        $matches = [];

        Client::query()
            ->when($ignoreClientId, fn ($q) => $q->whereKeyNot($ignoreClientId))
            ->where(fn ($q) => $this->whereAnyWord($q, 'name', $words))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'is_prospect'])
            ->each(function (Client $c) use (&$matches) {
                $matches[] = ['kind' => 'client', 'label' => ($c->is_prospect ? 'Prospect existant : ' : 'Client existant : ').$c->name];
            });

        LegalCase::query()
            ->whereNotNull('adverse_party')
            ->where(fn ($q) => $this->whereAnyWord($q, 'adverse_party', $words))
            ->orderBy('reference')
            ->limit(20)
            ->get(['reference', 'adverse_party'])
            ->each(function (LegalCase $case) use (&$matches) {
                $matches[] = ['kind' => 'adverse', 'label' => "Partie adverse dans {$case->reference} : {$case->adverse_party}"];
            });

        return $matches;
    }

    /**
     * Partie adverse d'un dossier comparée aux clients du cabinet.
     *
     * @return list<array{kind: string, label: string}>
     */
    public function matchesForAdverseParty(?string $adverse): array
    {
        $words = $this->words((string) $adverse);
        if ($words === []) {
            return [];
        }

        return Client::query()
            ->where(fn ($q) => $this->whereAnyWord($q, 'name', $words))
            ->orderBy('name')
            ->limit(20)
            ->pluck('name')
            ->map(fn (string $n) => ['kind' => 'client', 'label' => "{$n} est client du cabinet"])
            ->all();
    }

    /** @return list<string> */
    private function words(string $name): array
    {
        $ignored = ['sarl', 'sa', 'sas', 'suarl', 'sprl', 'scp', 'ets', 'les', 'des', 'et', 'de', 'du', 'la', 'le', 'c/'];

        return collect(preg_split('/[\s,.;\/\-]+/u', Str::lower(trim($name))) ?: [])
            ->filter(fn (string $w) => mb_strlen($w) > 2 && ! in_array($w, $ignored, true))
            ->unique()
            ->values()
            ->all();
    }

    private function whereAnyWord($query, string $column, array $words): void
    {
        foreach ($words as $word) {
            $query->orWhere($column, 'like', '%'.addcslashes($word, '%_\\').'%');
        }
    }
}
