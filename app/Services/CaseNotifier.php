<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\EmailTemplate;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifications liées aux dossiers.
 *
 * - Ouverture : administrateurs actifs, client (selon son consentement), avocats du dossier.
 * - Modification significative : client et avocats du dossier (jamais les administrateurs) ;
 *   avocat ajouté et avocat retiré reçoivent un message dédié.
 *
 * Confidentialité : les destinataires sont lus dans les relations actuelles du dossier ;
 * le client ne reçoit ni lien vers l'application ni information interne ; un avocat retiré
 * ne reçoit que la référence et l'intitulé du dossier.
 */
class CaseNotifier
{
    public function __construct(private readonly EmailService $emails) {}

    public function opened(LegalCase $case, ?User $by = null): void
    {
        $case = $this->fresh($case);
        $lawyers = $this->team($case);
        $vars = $this->commonVars($case, $by);

        // A. Avocats du dossier : un e-mail individuel chacun.
        foreach ($lawyers as $lawyer) {
            $this->safely(fn () => $this->emails->send(EmailTemplate::CASE_CREATED_LAWYER, $lawyer,
                $vars + $this->lawyerVars($case, $lawyer), $case, [], $by));
        }

        // B. Administrateurs actifs (sauf ceux déjà prévenus comme avocats du dossier).
        $admins = User::query()->role(Role::Admin->value)->where('is_active', true)
            ->whereNotIn('id', $lawyers->pluck('id'))->orderBy('name')->get();
        foreach ($admins as $admin) {
            $this->safely(fn () => $this->emails->send(EmailTemplate::CASE_CREATED_ADMIN, $admin,
                $vars + ['destinataire' => $admin->name], $case, [], $by));
        }

        // C. Client : bienvenue et confirmation (consentement vérifié par EmailService).
        $this->sendClient($case, EmailTemplate::CASE_CREATED_CLIENT, [], $by);
    }

    /**
     * @param  list<string>  $changes  lignes lisibles par le client et les avocats
     * @param  list<int>  $addedIds  avocats ajoutés à l'équipe
     * @param  list<int>  $removedIds  avocats retirés de l'équipe
     */
    public function updated(LegalCase $case, array $changes, array $addedIds, array $removedIds, bool $clientChanged, ?User $by = null): void
    {
        $case = $this->fresh($case);
        $vars = $this->commonVars($case, $by);
        $text = implode("\n", array_map(fn ($l) => '- '.$l, $changes));

        // Avocat retiré : message minimal, sans lien ni détail du dossier.
        foreach (User::query()->whereKey($removedIds)->get() as $removed) {
            $this->safely(fn () => $this->emails->send(EmailTemplate::CASE_LAWYER_REMOVED, $removed, [
                'destinataire' => $removed->name,
                'reference' => $case->reference,
                'dossier' => $case->title,
                'date' => today()->format('d/m/Y'),
            ], $case, [], $by));
        }

        foreach ($this->team($case) as $lawyer) {
            if (in_array($lawyer->id, $addedIds, true)) {
                $this->safely(fn () => $this->emails->send(EmailTemplate::CASE_LAWYER_ADDED, $lawyer,
                    $vars + $this->lawyerVars($case, $lawyer), $case, [], $by));
            } elseif ($changes !== []) {
                $this->safely(fn () => $this->emails->send(EmailTemplate::CASE_UPDATED_LAWYER, $lawyer,
                    $vars + $this->lawyerVars($case, $lawyer) + ['modifications' => $text], $case, [], $by));
            }
        }

        // Nouveau client : il découvre le dossier ; sinon, information sur les changements.
        if ($clientChanged) {
            $this->sendClient($case, EmailTemplate::CASE_CREATED_CLIENT, [], $by);
        } elseif ($changes !== []) {
            $this->sendClient($case, EmailTemplate::CASE_UPDATED_CLIENT, ['modifications' => $text], $by);
        }
    }

    /** Équipe actuelle : responsable puis collaborateurs (utilisateurs actifs ou non : EmailService filtre). */
    public function team(LegalCase $case): Collection
    {
        return collect([$case->leadLawyer])->merge($case->collaborators->sortBy('name'))->filter()->unique('id')->values();
    }

    /** « Me Joël Kabeya (responsable), Me Grâce Mukendi » */
    public function teamLabel(LegalCase $case, bool $forClient = false): string
    {
        return $this->team($case)->map(function (User $u) use ($case, $forClient) {
            $role = $u->id === $case->lead_lawyer_id ? 'responsable du dossier' : 'collaborateur';
            if ($forClient) {
                return $u->name.($u->title ? ', '.mb_strtolower($u->title) : '').($u->id === $case->lead_lawyer_id ? ' (responsable du dossier)' : '');
            }

            return $u->name.' ('.$role.')';
        })->join(', ', ' et ');
    }

    /** @return array<string, string|null> */
    private function commonVars(LegalCase $case, ?User $by): array
    {
        return [
            'client' => $case->client->name,
            'reference' => $case->reference,
            'dossier' => $case->title,
            'date' => $case->opened_on->format('d/m/Y'),
            'avocats' => $this->teamLabel($case),
            'createur' => $by?->name ?? 'LexCabinet',
            'lien' => route('cases.show', $case),
            'prise_en_charge' => $this->handling($case),
        ];
    }

    /** @return array<string, string> */
    private function lawyerVars(LegalCase $case, User $lawyer): array
    {
        return [
            'destinataire' => $lawyer->name,
            'avocat' => $lawyer->name,
            'qualite' => $lawyer->id === $case->lead_lawyer_id ? 'avocat responsable' : 'avocat collaborateur',
        ];
    }

    /** Informations de prise en charge, réservées au cabinet (avocats et administrateurs). */
    private function handling(LegalCase $case): string
    {
        return implode("\n", array_filter([
            'Matière : '.$case->matter->label(),
            $case->jurisdiction ? 'Juridiction : '.$case->jurisdiction : null,
            $case->docket_number ? 'N° de rôle : '.$case->docket_number : null,
            $case->adverse_party ? 'Partie adverse : '.$case->adverse_party : null,
            'Convention d\'honoraires : '.$case->fee_type->label().', '.Money::format((string) $case->fee_amount, $case->currency).$case->fee_type->amountSuffix(),
            'Statut : '.$case->status->label(),
        ]));
    }

    /** Variables destinées au client : pas de lien vers l'application, pas d'information interne. */
    private function sendClient(LegalCase $case, string $template, array $extra, ?User $by): void
    {
        $this->safely(fn () => $this->emails->send($template, $case->client, $extra + [
            'client' => $case->client->name,
            'destinataire' => $case->client->name,
            'reference' => $case->reference,
            'dossier' => $case->title,
            'date' => $case->opened_on->format('d/m/Y'),
            'avocats' => $this->teamLabel($case, forClient: true),
        ], $case, [], $by));
    }

    private function fresh(LegalCase $case): LegalCase
    {
        return $case->fresh(['client', 'leadLawyer', 'collaborators']);
    }

    /** Une notification en erreur ne doit jamais faire échouer l'opération métier. */
    private function safely(\Closure $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::error('Notification de dossier non créée : '.$e->getMessage());
        }
    }
}
