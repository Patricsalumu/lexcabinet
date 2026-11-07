<?php

namespace App\Services;

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fiches clients (CLI-01 à CLI-04).
 */
class ClientService
{
    public function __construct(private readonly ConflictCheckService $conflicts) {}

    /**
     * Crée une fiche. S'il existe des correspondances (conflits d'intérêts possibles),
     * l'utilisateur doit confirmer les avoir vérifiées ; la confirmation est tracée.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $by, bool $conflictAcknowledged = false): Client
    {
        $matches = $this->conflicts->matchesForName($data['name']);

        if ($matches !== [] && ! $conflictAcknowledged) {
            throw ValidationException::withMessages([
                'conflict_ack' => 'Des correspondances existent : confirmez l\'absence de conflit d\'intérêts avant de créer la fiche.',
            ]);
        }

        return DB::transaction(function () use ($data, $by, $matches) {
            $client = Client::query()->create($this->clean($data) + ['created_by' => $by->id]);

            activity('clients')->causedBy($by)->performedOn($client)
                ->withProperties(['matches' => array_column($matches, 'label')])
                ->log($matches === []
                    ? 'Contrôle des conflits d\'intérêts : aucune correspondance'
                    : 'Contrôle des conflits d\'intérêts : '.count($matches).' correspondance(s) vérifiée(s), absence de conflit confirmée');

            return $client;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Client $client, array $data): Client
    {
        $client->update($this->clean($data));

        return $client;
    }

    /**
     * Ne garde que les champs du type de client (physique ou morale).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $type = $data['type'] instanceof ClientType ? $data['type'] : ClientType::from($data['type']);
        $individual = $type === ClientType::Individual;

        $value = fn (string $key) => filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;

        return [
            'type' => $type,
            'name' => trim($data['name']),
            'phone' => $value('phone'),
            'email' => $value('email') ? mb_strtolower($value('email')) : null,
            'address' => $value('address'),
            'id_document_type' => $individual ? $value('id_document_type') : null,
            'id_document_number' => $individual ? $value('id_document_number') : null,
            'rccm' => $individual ? null : $value('rccm'),
            'id_nat' => $individual ? null : $value('id_nat'),
            'representative' => $individual ? null : $value('representative'),
            'email_consent' => (bool) ($data['email_consent'] ?? false),
            'is_prospect' => (bool) ($data['is_prospect'] ?? false),
        ];
    }
}
