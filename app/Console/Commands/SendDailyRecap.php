<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsForEachFirm;
use App\Services\ReminderService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lexcabinet:send-daily-recap {--firm= : Ne traiter que ce cabinet (identifiant)}')]
#[Description('Envoie à chaque avocat le récapitulatif de ses rendez-vous et audiences du jour')]
class SendDailyRecap extends Command
{
    use RunsForEachFirm;

    public function handle(ReminderService $reminders): int
    {
        return $this->forEachFirm(fn () => 'Récapitulatifs envoyés : '.$reminders->sendDailyRecaps().'.');
    }
}
