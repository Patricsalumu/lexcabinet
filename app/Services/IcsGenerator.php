<?php

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Carbon;

/**
 * Fichier iCalendar (RFC 5545) joint aux e-mails de rendez-vous (NOT-08).
 * METHOD:REQUEST pour une création ou modification, METHOD:CANCEL pour une annulation ou un report.
 */
class IcsGenerator
{
    public const REQUEST = 'REQUEST';

    public const CANCEL = 'CANCEL';

    public function __construct(private readonly SettingsService $settings) {}

    public function forAppointment(Appointment $appointment, string $method = self::REQUEST): string
    {
        $appointment->loadMissing(['lawyer', 'client']);
        $firm = (string) $this->settings->get('firm.name');
        $firmEmail = (string) ($this->settings->get('firm.email') ?: config('mail.from.address'));
        $cancel = $method === self::CANCEL;

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//LexCabinet//Agenda//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:'.$method,
            'BEGIN:VEVENT',
            'UID:'.$this->uid($appointment),
            'SEQUENCE:'.$appointment->ics_sequence,
            'DTSTAMP:'.$this->utc(now()),
            'DTSTART:'.$this->utc($appointment->starts_at),
            'DTEND:'.$this->utc($appointment->ends_at),
            'SUMMARY:'.$this->escape($appointment->subject.' — '.$firm),
            'LOCATION:'.$this->escape($appointment->locationLabel()),
            'DESCRIPTION:'.$this->escape($appointment->type->label().' avec '.$appointment->lawyer->name),
            'ORGANIZER;CN='.$this->escape($firm).':mailto:'.$firmEmail,
            'STATUS:'.($cancel ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(fn (string $l) => $this->fold($l), $lines))."\r\n";
    }

    public function uid(Appointment $appointment): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'lexcabinet.local';

        return "rdv-{$appointment->id}@{$host}";
    }

    private function utc(\DateTimeInterface $date): string
    {
        return Carbon::instance($date)->utc()->format('Ymd\THis\Z');
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $text);
    }

    /** Coupe les lignes à 75 octets sans casser un caractère UTF-8. */
    private function fold(string $line): string
    {
        $out = '';
        $current = '';
        $limit = 75; // première ligne : 75 octets ; suivantes : espace + 74 octets
        foreach (mb_str_split($line) as $char) {
            if (strlen($current.$char) > $limit) {
                $out .= $current."\r\n ";
                $current = '';
                $limit = 74;
            }
            $current .= $char;
        }

        return $out.$current;
    }
}
