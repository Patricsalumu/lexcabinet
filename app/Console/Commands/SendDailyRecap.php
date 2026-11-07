<?php

namespace App\Console\Commands;

use App\Services\ReminderService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lexcabinet:send-daily-recap')]
#[Description('Envoie à chaque avocat le récapitulatif de ses rendez-vous et audiences du jour')]
class SendDailyRecap extends Command
{
    public function handle(ReminderService $reminders): int
    {
        $sent = $reminders->sendDailyRecaps();

        $this->info("Récapitulatifs envoyés : {$sent}.");

        return self::SUCCESS;
    }
}
