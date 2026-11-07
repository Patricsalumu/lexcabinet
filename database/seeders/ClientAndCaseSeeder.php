<?php

namespace Database\Seeders;

use App\Enums\CaseEventType;
use App\Enums\CaseStatus;
use App\Enums\ClientType;
use App\Enums\Currency;
use App\Enums\FeeType;
use App\Enums\Matter;
use App\Models\Client;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\CaseService;
use App\Services\NumberingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Clients et dossiers de démonstration repris du prototype.
 * Les dates sont relatives à aujourd'hui, comme dans le prototype.
 */
class ClientAndCaseSeeder extends Seeder
{
    public function run(NumberingService $numbering): void
    {
        $user = fn (string $email) => User::query()->where('email', $email.'@mukendi-avocats.example')->firstOrFail();
        $grace = $user('grace.mukendi');
        $joel = $user('joel.kabeya');
        $sarah = $user('sarah.ilunga');
        $nadine = $user('nadine.lukusa');

        $client = fn (array $data) => Client::query()->create($data + ['created_by' => $nadine->id]);

        $kivu = $client(['type' => ClientType::Company, 'name' => 'Kivu Agro SARL', 'phone' => '+243 99 712 4410', 'email' => 'direction@kivuagro.example', 'address' => 'Av. du Lac 45, Goma', 'rccm' => 'CD/GOM/RCCM/21-B-00412', 'id_nat' => '5-93-N71204M', 'representative' => 'M. Pascal Bahati, directeur général', 'email_consent' => true]);
        $lumbala = $client(['type' => ClientType::Individual, 'name' => 'Jean-Pierre Lumbala', 'phone' => '+243 81 554 2098', 'email' => 'jp.lumbala@example.cd', 'address' => 'Q. Matonge, Kalamu, Kinshasa', 'email_consent' => true]);
        $kalala = $client(['type' => ClientType::Individual, 'name' => 'Esther Kalala', 'phone' => '+243 85 220 7731', 'email' => 'esther.kalala@example.cd', 'address' => 'Av. Kasa-Vubu 210, Bandalungwa', 'email_consent' => true]);
        $congoExpress = $client(['type' => ClientType::Company, 'name' => 'Congo Express Transports SARL', 'phone' => '+243 97 301 8822', 'email' => 'juridique@congoexpress.example', 'address' => 'Bd du 30 Juin 1550, Gombe', 'rccm' => 'CD/KNG/RCCM/14-B-07719', 'id_nat' => '01-72-N50911G', 'email_consent' => true]);
        $ngoy = $client(['type' => ClientType::Individual, 'name' => 'Patrice Ngoy', 'phone' => '+243 82 640 1177', 'email' => null, 'address' => 'Kamalondo, Lubumbashi', 'email_consent' => false]);
        $client(['type' => ClientType::Individual, 'name' => 'Rachel Mwamba', 'phone' => '+243 89 118 3307', 'email' => 'rachel.mwamba@example.cd', 'address' => 'Limete, Kinshasa', 'email_consent' => true, 'is_prospect' => true]);

        $year = now()->year;
        $day = fn (int $offset, string $time = '09:00') => Carbon::today()->addDays($offset)->setTimeFromTimeString($time);

        $case = function (int $number, array $data, array $collaborators = []) use ($year, $nadine) {
            $opened = Carbon::parse($data['opened_on']);
            $case = LegalCase::query()->create($data + [
                'reference' => sprintf('LEX-%d-%04d', $year, $number),
                'created_by' => $nadine->id,
                // Abonnement : les périodes déjà écoulées sont réputées facturées (voir InvoiceSeeder) ;
                // la prochaine échéance est la fin de la période en cours.
                'next_subscription_invoice_on' => $data['fee_type'] === FeeType::Subscription
                    ? $opened->copy()->addDays(CaseService::SUBSCRIPTION_PERIOD_DAYS * max(1, intdiv((int) $opened->diffInDays(today()), CaseService::SUBSCRIPTION_PERIOD_DAYS)))
                    : null,
            ]);
            $case->collaborators()->sync(array_map(fn (User $u) => $u->id, $collaborators));

            return $case;
        };

        // LEX-0038 : forfait 2 500 $
        $d1 = $case(38, ['title' => 'Kivu Agro c/ Tropical Logistics', 'client_id' => $kivu->id, 'adverse_party' => 'Tropical Logistics SA', 'lead_lawyer_id' => $joel->id, 'matter' => Matter::Commercial, 'jurisdiction' => 'Tribunal de commerce de Kinshasa/Gombe', 'docket_number' => 'RCE 1123', 'status' => CaseStatus::InProgress, 'fee_type' => FeeType::Flat, 'fee_amount' => '2500.00', 'currency' => Currency::USD, 'opened_on' => $day(-60)->toDateString()], [$grace]);
        $d1->events()->createMany([
            ['type' => CaseEventType::Act, 'occurred_at' => $day(-40), 'description' => 'Assignation signifiée à Tropical Logistics SA', 'created_by' => $nadine->id],
            ['type' => CaseEventType::Hearing, 'occurred_at' => $day(-10, '09:00'), 'description' => 'Mise en état, renvoi contradictoire', 'chamber' => 'Chambre 2', 'created_by' => $nadine->id],
            ['type' => CaseEventType::Hearing, 'occurred_at' => $day(0, '11:30'), 'description' => 'Plaidoiries', 'chamber' => 'Chambre 2', 'created_by' => $nadine->id],
        ]);
        $d1->timeEntries()->create(['user_id' => $joel->id, 'worked_on' => $day(-35)->toDateString(), 'minutes' => 180, 'description' => 'Rédaction des conclusions', 'billable' => false]);

        // LEX-0041 : taux horaire 120 $
        $d2 = $case(41, ['title' => 'Lumbala, litige parcellaire Kimbanseke', 'client_id' => $lumbala->id, 'adverse_party' => 'Héritiers Mpiana', 'lead_lawyer_id' => $sarah->id, 'matter' => Matter::Land, 'jurisdiction' => 'Tribunal de grande instance de Kinshasa/Ndjili', 'docket_number' => 'RC 4471', 'status' => CaseStatus::InProgress, 'fee_type' => FeeType::Hourly, 'fee_amount' => '120.00', 'currency' => Currency::USD, 'opened_on' => $day(-30)->toDateString()]);
        $d2->events()->createMany([
            ['type' => CaseEventType::Act, 'occurred_at' => $day(-15), 'description' => 'Procès-verbal de descente sur la parcelle', 'created_by' => $nadine->id],
            ['type' => CaseEventType::Note, 'occurred_at' => $day(-1), 'description' => 'Copie du certificat d\'enregistrement reçue', 'created_by' => $nadine->id],
        ]);
        // Les deux premières entrées seront rattachées à la facture FAC-...-00117 en phase 4.
        $d2->timeEntries()->createMany([
            ['user_id' => $sarah->id, 'worked_on' => $day(-20)->toDateString(), 'minutes' => 120, 'description' => 'Analyse du certificat d\'enregistrement', 'billable' => true],
            ['user_id' => $sarah->id, 'worked_on' => $day(-15)->toDateString(), 'minutes' => 240, 'description' => 'Descente sur la parcelle et procès-verbal', 'billable' => true],
            ['user_id' => $sarah->id, 'worked_on' => $day(-4)->toDateString(), 'minutes' => 90, 'description' => 'Préparation de l\'audience', 'billable' => true],
            ['user_id' => $sarah->id, 'worked_on' => $day(-2)->toDateString(), 'minutes' => 60, 'description' => 'Échanges avec le conservateur des titres immobiliers', 'billable' => true],
        ]);

        // LEX-0044 : provision + résultat 600 $
        $case(44, ['title' => 'Kalala c/ Société Minière du Sud', 'client_id' => $kalala->id, 'adverse_party' => 'Société Minière du Sud SA', 'lead_lawyer_id' => $joel->id, 'matter' => Matter::Labour, 'jurisdiction' => 'Tribunal de travail de Kinshasa/Gombe', 'docket_number' => 'RAT 902', 'status' => CaseStatus::Open, 'fee_type' => FeeType::RetainerSuccess, 'fee_amount' => '600.00', 'currency' => Currency::USD, 'opened_on' => $day(-5)->toDateString()]);

        // LEX-0029 : abonnement 800 $ / 30 jours
        $case(29, ['title' => 'Congo Express, conseil juridique OHADA', 'client_id' => $congoExpress->id, 'adverse_party' => null, 'lead_lawyer_id' => $grace->id, 'matter' => Matter::Advisory, 'jurisdiction' => null, 'docket_number' => null, 'status' => CaseStatus::InProgress, 'fee_type' => FeeType::Subscription, 'fee_amount' => '800.00', 'currency' => Currency::USD, 'opened_on' => $day(-120)->toDateString()], [$joel]);

        // LEX-0046 : forfait 2 800 000 FC
        $d5 = $case(46, ['title' => 'Ministère public c/ Ngoy', 'client_id' => $ngoy->id, 'adverse_party' => 'Ministère public', 'lead_lawyer_id' => $sarah->id, 'matter' => Matter::Criminal, 'jurisdiction' => 'Tribunal de paix de Lubumbashi/Kamalondo', 'docket_number' => 'RP 3318', 'status' => CaseStatus::Deliberation, 'fee_type' => FeeType::Flat, 'fee_amount' => '2800000.00', 'currency' => Currency::CDF, 'opened_on' => $day(-45)->toDateString()]);
        $d5->events()->createMany([
            ['type' => CaseEventType::Hearing, 'occurred_at' => $day(-12, '09:30'), 'description' => 'Plaidoiries, prise en délibéré', 'created_by' => $nadine->id],
            ['type' => CaseEventType::Hearing, 'occurred_at' => $day(3, '09:30'), 'description' => 'Prononcé RP 3318', 'created_by' => $nadine->id],
        ]);

        // Le prochain dossier ouvert recevra LEX-AAAA-0047.
        $numbering->set("case:{$year}", 46);
    }
}
