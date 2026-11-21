<?php

namespace App\Services;

use App\Enums\CaseEventType;
use App\Enums\CaseStatus;
use App\Enums\Currency;
use App\Enums\FeeType;
use App\Enums\Role;
use App\Models\CaseEvent;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dossiers (DOS-01 à DOS-05, DOS-08) : ouverture numérotée, convention d'honoraires,
 * collaborateurs, statuts et journal.
 */
class CaseService
{
    /** Période d'un abonnement (FAC-14). */
    public const SUBSCRIPTION_PERIOD_DAYS = 30;

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly ConflictCheckService $conflicts,
        private readonly CaseNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $collaboratorIds
     */
    public function open(array $data, array $collaboratorIds, User $by): LegalCase
    {
        $this->assertLawyers([$data['lead_lawyer_id'], ...$collaboratorIds]);

        $openedOn = Carbon::parse($data['opened_on'] ?? today())->startOfDay();
        $feeType = FeeType::from($data['fee_type'] instanceof FeeType ? $data['fee_type']->value : $data['fee_type']);
        $adverseMatches = $this->conflicts->matchesForAdverseParty($data['adverse_party'] ?? null);

        return DB::transaction(function () use ($data, $collaboratorIds, $by, $openedOn, $feeType, $adverseMatches) {
            $case = LegalCase::query()->create($this->attributes($data) + [
                'reference' => $this->numbering->nextCaseReference($openedOn->year),
                'status' => CaseStatus::Open,
                'opened_on' => $openedOn->toDateString(),
                'next_subscription_invoice_on' => $feeType === FeeType::Subscription
                    ? $openedOn->copy()->addDays(self::SUBSCRIPTION_PERIOD_DAYS)->toDateString()
                    : null,
                'created_by' => $by->id,
            ]);

            $this->syncCollaborators($case, $collaboratorIds, $by, log: false);

            if ($adverseMatches !== []) {
                activity('cases')->causedBy($by)->performedOn($case)
                    ->withProperties(['matches' => array_column($adverseMatches, 'label')])
                    ->log('Alerte conflit d\'intérêts : la partie adverse correspond à un client du cabinet');
            }

            // Notifications seulement une fois le dossier réellement enregistré (après commit).
            DB::afterCommit(fn () => $this->notifier->opened($case, $by));

            return $case;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $collaboratorIds
     */
    public function update(LegalCase $case, array $data, array $collaboratorIds, User $by): LegalCase
    {
        $this->assertLawyers([$data['lead_lawyer_id'], ...$collaboratorIds]);

        return DB::transaction(function () use ($case, $data, $collaboratorIds, $by) {
            $before = $this->snapshot($case);
            $attributes = $this->attributes($data);
            $feeType = $attributes['fee_type'];

            // DOS-08 : l'abonnement démarre 30 jours après l'ouverture.
            if ($feeType === FeeType::Subscription && $case->next_subscription_invoice_on === null && $case->status->isActive()) {
                $attributes['next_subscription_invoice_on'] = $case->opened_on->copy()->addDays(self::SUBSCRIPTION_PERIOD_DAYS)->toDateString();
            } elseif ($feeType !== FeeType::Subscription) {
                $attributes['next_subscription_invoice_on'] = null;
            }

            $case->update($attributes);
            $this->syncCollaborators($case, $collaboratorIds, $by);
            $case->refresh();

            $this->notifyChanges($case, $before, $by);

            return $case;
        });
    }

    /** DOS-03 ; la clôture arrête la facturation d'abonnement (DOS-08). */
    public function changeStatus(LegalCase $case, CaseStatus $status, User $by): void
    {
        if ($case->status === $status) {
            return;
        }

        $before = $this->snapshot($case);

        $case->update([
            'status' => $status,
            'closed_on' => $status->isActive() ? null : ($case->closed_on ?? today()),
        ]);

        activity('cases')->causedBy($by)->performedOn($case)
            ->withProperties(['new' => $status->label()])
            ->log('Statut du dossier modifié : '.$status->label());

        $this->notifyChanges($case->refresh(), $before, $by);
    }

    /**
     * État notifiable d'un dossier : seuls ces champs justifient d'informer le client et l'équipe.
     *
     * @return array<string, mixed>
     */
    private function snapshot(LegalCase $case): array
    {
        $case->loadMissing(['leadLawyer', 'collaborators']);

        return [
            'Intitulé' => $case->title,
            'Matière' => $case->matter->label(),
            'Juridiction' => $case->jurisdiction,
            'N° de rôle' => $case->docket_number,
            'Partie adverse' => $case->adverse_party,
            'Convention d\'honoraires' => $case->fee_type->label().', '.\App\Support\Money::format((string) $case->fee_amount, $case->currency).$case->fee_type->amountSuffix(),
            'Statut' => $case->status->label(),
            'Avocat responsable' => $case->leadLawyer?->name,
            '_client' => $case->client_id,
            '_team' => collect([$case->lead_lawyer_id])->merge($case->collaborators()->pluck('users.id'))->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
        ];
    }

    /**
     * Compare avant/après et programme les notifications (après commit) s'il y a un changement
     * significatif ; une modification sans effet réel n'envoie rien.
     */
    private function notifyChanges(LegalCase $case, array $before, User $by): void
    {
        $case->unsetRelation('collaborators')->unsetRelation('leadLawyer');
        $after = $this->snapshot($case);

        $changes = [];
        foreach ($before as $label => $old) {
            if (str_starts_with($label, '_') || $old === $after[$label]) {
                continue;
            }
            $changes[] = $label.' : '.($old ?? '—').' → '.($after[$label] ?? '—');
        }

        $added = array_values(array_diff($after['_team'], $before['_team']));
        $removed = array_values(array_diff($before['_team'], $after['_team']));
        if ($added !== [] || $removed !== []) {
            $changes[] = 'Équipe : '.$this->notifier->teamLabel($case->fresh(['leadLawyer', 'collaborators']), forClient: true);
        }
        $clientChanged = $before['_client'] !== $after['_client'];

        if ($changes === [] && ! $clientChanged) {
            return;
        }

        DB::afterCommit(fn () => $this->notifier->updated($case, $changes, $added, $removed, $clientChanged, $by));
    }

    /** @param  array<string, mixed>  $data */
    public function addEvent(LegalCase $case, array $data, User $by): CaseEvent
    {
        $type = CaseEventType::from($data['type']);

        return $case->events()->create([
            'type' => $type,
            'occurred_at' => Carbon::parse($data['occurred_at']),
            'description' => trim($data['description']),
            'chamber' => $type === CaseEventType::Hearing && filled($data['chamber'] ?? null) ? trim($data['chamber']) : null,
            'created_by' => $by->id,
        ]);
    }

    /** @param  list<int>  $ids */
    private function syncCollaborators(LegalCase $case, array $ids, User $by, bool $log = true): void
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === (int) $case->lead_lawyer_id)->unique()->values()->all();
        $changes = $case->collaborators()->sync($ids);

        if ($log && ($changes['attached'] !== [] || $changes['detached'] !== [])) {
            activity('cases')->causedBy($by)->performedOn($case)
                ->withProperties([
                    'new' => User::query()->whereKey($ids)->orderBy('name')->pluck('name')->all(),
                ])
                ->log('Collaborateurs du dossier modifiés');
        }
    }

    /** @param  list<int|string>  $ids */
    private function assertLawyers(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $count = User::query()->whereKey($ids)->where('is_active', true)->role(Role::Lawyer->value)->count();

        if ($count !== count($ids)) {
            throw ValidationException::withMessages([
                'lead_lawyer_id' => 'Le responsable et les collaborateurs doivent être des avocats actifs du cabinet.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $text = fn (string $key) => filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;

        return [
            'title' => trim($data['title']),
            'client_id' => (int) $data['client_id'],
            'adverse_party' => $text('adverse_party'),
            'lead_lawyer_id' => (int) $data['lead_lawyer_id'],
            'matter' => $data['matter'],
            'jurisdiction' => $text('jurisdiction'),
            'docket_number' => $text('docket_number'),
            'fee_type' => FeeType::from($data['fee_type'] instanceof FeeType ? $data['fee_type']->value : $data['fee_type']),
            'fee_amount' => Money::normalize((string) $data['fee_amount']),
            'currency' => Currency::from($data['currency'] instanceof Currency ? $data['currency']->value : $data['currency']),
        ];
    }
}
