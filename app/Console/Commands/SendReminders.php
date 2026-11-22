<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsForEachFirm;
use App\Services\ReminderService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lexcabinet:send-reminders {--firm= : Ne traiter que ce cabinet (identifiant)}')]
#[Description('Envoie les rappels de rendez-vous (J-1 à l\'heure paramétrée, et H-N)')]
class SendReminders extends Command
{
    use RunsForEachFirm;

    public function handle(ReminderService $reminders): int
    {
        return $this->forEachFirm(function () use ($reminders) {
            $dayBefore = $reminders->sendDayBeforeReminders();
            $short = $reminders->sendShortReminders();

            return "Rappels J-1 : {$dayBefore} ; rappels rapprochés : {$short}.";
        });
    }
}
