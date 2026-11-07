<?php

namespace App\Services;

use App\Models\LegalCase;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Saisie du temps passé (DOS-05). L'auteur saisit toujours son propre temps.
 */
class TimeEntryService
{
    public function log(LegalCase $case, User $user, string $workedOn, int $minutes, string $description, bool $billable): TimeEntry
    {
        return $case->timeEntries()->create([
            'user_id' => $user->id,
            'worked_on' => Carbon::parse($workedOn)->toDateString(),
            'minutes' => $minutes,
            'description' => trim($description),
            'billable' => $billable,
        ]);
    }
}
