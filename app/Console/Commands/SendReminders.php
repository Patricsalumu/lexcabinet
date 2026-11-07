<?php

namespace App\Console\Commands;

use App\Services\ReminderService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lexcabinet:send-reminders')]
#[Description('Envoie les rappels de rendez-vous (J-1 à l\'heure paramétrée, et H-N)')]
class SendReminders extends Command
{
    public function handle(ReminderService $reminders): int
    {
        $dayBefore = $reminders->sendDayBeforeReminders();
        $short = $reminders->sendShortReminders();

        $this->info("Rappels J-1 : {$dayBefore} ; rappels rapprochés : {$short}.");

        return self::SUCCESS;
    }
}
