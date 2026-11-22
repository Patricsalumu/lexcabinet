<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Mail\TemplatedMail;
use App\Services\Mailbox\FirmMailConfig;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Messagerie du cabinet (phase M3) : son serveur d'envoi (SMTP) et sa boîte de réponses (IMAP).
 * Sans configuration, la plateforme envoie et relève pour le cabinet. Les mots de passe sont
 * chiffrés, jamais réaffichés : un champ laissé vide conserve le mot de passe enregistré.
 */
#[Layout('layouts.app')]
#[Title('Messagerie du cabinet')]
class MailSettings extends Component
{
    public bool $smtp_enabled = false;

    public string $smtp_host = '';

    public int $smtp_port = 465;

    public string $smtp_encryption = 'ssl';

    public string $smtp_username = '';

    public string $smtp_password = '';

    public string $from_address = '';

    public bool $imap_enabled = false;

    public string $imap_host = '';

    public int $imap_port = 993;

    public string $imap_encryption = 'ssl';

    public string $imap_username = '';

    public string $imap_password = '';

    public string $imap_folders = 'INBOX';

    public string $imap_sent_folder = 'Sent';

    public ?string $testResult = null;

    public bool $testOk = false;

    public function mount(SettingsService $settings): void
    {
        $this->authorize(Permission::SettingsManage->value);

        foreach (['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'from_address',
            'imap_enabled', 'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_folders', 'imap_sent_folder'] as $field) {
            $value = $settings->get('mail.'.$field);
            if ($value !== null) {
                $this->{$field} = is_bool($this->{$field}) ? (bool) $value : (is_int($this->{$field}) ? (int) $value : (string) $value);
            }
        }
    }

    public function save(SettingsService $settings): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $this->validate($this->rules(), [], $this->attributes());

        $values = [
            'mail.smtp_enabled' => $this->smtp_enabled,
            'mail.smtp_host' => trim($this->smtp_host),
            'mail.smtp_port' => $this->smtp_port,
            'mail.smtp_encryption' => $this->smtp_encryption,
            'mail.smtp_username' => trim($this->smtp_username),
            'mail.from_address' => trim($this->from_address),
            'mail.imap_enabled' => $this->imap_enabled,
            'mail.imap_host' => trim($this->imap_host),
            'mail.imap_port' => $this->imap_port,
            'mail.imap_encryption' => $this->imap_encryption,
            'mail.imap_username' => trim($this->imap_username),
            'mail.imap_folders' => trim($this->imap_folders) ?: 'INBOX',
            'mail.imap_sent_folder' => trim($this->imap_sent_folder),
        ];
        // Mot de passe : seulement s'il a été saisi (sinon on garde celui enregistré).
        if ($this->smtp_password !== '') {
            $values['mail.smtp_password'] = $this->smtp_password;
        }
        if ($this->imap_password !== '') {
            $values['mail.imap_password'] = $this->imap_password;
        }

        $settings->setMany($values, auth()->user());
        $this->reset('smtp_password', 'imap_password');
        session()->flash('status', 'Messagerie enregistrée.');
    }

    /** Envoie immédiatement, par le SMTP du cabinet, un e-mail d'essai à l'utilisateur connecté. */
    public function testSmtp(FirmMailConfig $mail): void
    {
        $this->authorize(Permission::SettingsManage->value);
        if (! $mail->smtpConfigured()) {
            $this->result(false, 'Activez et enregistrez d\'abord le serveur d\'envoi (serveur, identifiant et mot de passe).');

            return;
        }

        $to = auth()->user()->email;
        try {
            Mail::mailer($mail->registerMailer())->raw(
                'E-mail d\'essai envoyé par LexCabinet le '.now()->format('d/m/Y à H:i').' avec le serveur d\'envoi du cabinet.',
                fn ($m) => $m->to($to)->from((string) ($this->from_address ?: $this->smtp_username))->subject('Essai d\'envoi LexCabinet'),
            );
            $this->result(true, "Envoi accepté par le serveur. Vérifiez la réception dans la boîte {$to} (et les indésirables).");
            activity('settings')->causedBy(auth()->user())->log('Essai d\'envoi SMTP réussi');
        } catch (Throwable $e) {
            $this->result(false, 'Échec de l\'envoi : '.$e->getMessage());
        }
    }

    /** Se connecte à la boîte du cabinet et liste ses dossiers (lecture seule). */
    public function testImap(FirmMailConfig $mail): void
    {
        $this->authorize(Permission::SettingsManage->value);
        $mailbox = $mail->ownMailbox();
        if ($mailbox === null) {
            $this->result(false, 'Activez et enregistrez d\'abord la boîte de réception (serveur, identifiant et mot de passe).');

            return;
        }

        try {
            $folders = $mailbox->folders();
            $this->result(true, 'Connexion réussie. Dossiers : '.implode(', ', $folders).'.');
        } catch (Throwable $e) {
            $this->result(false, 'Connexion impossible : '.$e->getMessage());
        }
    }

    public function render(SettingsService $settings, FirmMailConfig $mail): View
    {
        return view('livewire.admin.mail-settings', [
            'hasSmtpPassword' => $settings->hasSecret('mail.smtp_password'),
            'hasImapPassword' => $settings->hasSecret('mail.imap_password'),
            'replyAddress' => TemplatedMail::replyAddress(),
            'platformFrom' => (string) config('mail.from.address'),
            'usesOwnSmtp' => $mail->smtpConfigured(),
            'readsOwnMailbox' => $mail->imapConfigured(),
        ]);
    }

    private function result(bool $ok, string $message): void
    {
        $this->testOk = $ok;
        $this->testResult = $message;
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        $smtp = $this->smtp_enabled ? 'required' : 'nullable';
        $imap = $this->imap_enabled ? 'required' : 'nullable';

        return [
            'smtp_host' => [$smtp, 'string', 'max:150'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'in:ssl,tls'],
            'smtp_username' => [$smtp, 'string', 'max:150'],
            'smtp_password' => [$this->smtp_enabled && ! app(SettingsService::class)->hasSecret('mail.smtp_password') ? 'required' : 'nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'email', 'max:150'],
            'imap_host' => [$imap, 'string', 'max:150'],
            'imap_port' => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', 'in:ssl,tls,starttls'],
            'imap_username' => [$imap, 'string', 'max:150'],
            'imap_password' => [$this->imap_enabled && ! app(SettingsService::class)->hasSecret('mail.imap_password') ? 'required' : 'nullable', 'string', 'max:255'],
            'imap_folders' => ['nullable', 'string', 'max:255'],
            'imap_sent_folder' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'smtp_host' => 'serveur d\'envoi', 'smtp_username' => 'identifiant', 'smtp_password' => 'mot de passe',
            'from_address' => 'adresse d\'expédition', 'imap_host' => 'serveur de réception', 'imap_username' => 'identifiant',
            'imap_password' => 'mot de passe',
        ];
    }
}
