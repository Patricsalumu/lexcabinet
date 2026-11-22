<?php

namespace App\Livewire\Admin;

use App\Enums\Currency;
use App\Enums\Permission;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Identité du cabinet et règles (ADM-01, ADM-03).
 */
#[Layout('layouts.app')]
#[Title('Paramètres du cabinet')]
class FirmSettings extends Component
{
    use WithFileUploads;

    /** @var array<string, mixed> */
    public array $firm = [];

    /** @var array<string, mixed> */
    public array $rules = [];

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public ?string $logoPath = null;

    public function mount(SettingsService $settings): void
    {
        $this->authorize(Permission::SettingsManage->value);

        $all = $settings->all();

        $this->firm = [
            'name' => $all['firm.name'],
            'bar' => $all['firm.bar'],
            'address' => $all['firm.address'],
            'rccm' => $all['firm.rccm'],
            'id_nat' => $all['firm.id_nat'],
            'nif' => $all['firm.nif'],
            'phone' => $all['firm.phone'],
            'email' => $all['firm.email'],
            'bank_details' => $all['firm.bank_details'],
        ];

        $this->rules = [
            'vat_enabled' => (bool) $all['vat.enabled'],
            'vat_rate' => $all['vat.rate'],
            'threshold' => $all['cash.disbursement_threshold_usd'],
            'consultation_fee' => $all['consultation.default_fee'],
            'consultation_currency' => $all['consultation.default_currency'],
            'subscription_auto_issue' => (bool) $all['subscription_auto_issue'],
            'reminder_time' => $all['reminders.day_before_time'],
            'reminder_hours' => $all['reminders.hours_before'],
            'recap_time' => $all['recap.time'],
            'opens_at' => $all['office.opens_at'],
            'closes_at' => $all['office.closes_at'],
            'days' => array_map('strval', $all['office.days'] ?? []),
        ];

        $this->logoPath = $all['firm.logo_path'];
    }

    public function save(SettingsService $settings): void
    {
        $this->authorize(Permission::SettingsManage->value);

        $money = ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'];
        $time = ['required', 'date_format:H:i'];

        $this->validate([
            'firm.name' => ['required', 'string', 'max:150'],
            'firm.bar' => ['nullable', 'string', 'max:150'],
            'firm.address' => ['nullable', 'string', 'max:255'],
            'firm.rccm' => ['nullable', 'string', 'max:60'],
            'firm.id_nat' => ['nullable', 'string', 'max:60'],
            'firm.nif' => ['nullable', 'string', 'max:60'],
            'firm.phone' => ['nullable', 'string', 'max:30'],
            'firm.email' => ['nullable', 'email', 'max:150'],
            'firm.bank_details' => ['nullable', 'string', 'max:255'],
            'rules.vat_enabled' => ['boolean'],
            'rules.vat_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'rules.threshold' => $money,
            'rules.consultation_fee' => $money,
            'rules.consultation_currency' => ['required', Rule::enum(Currency::class)],
            'rules.subscription_auto_issue' => ['boolean'],
            'rules.reminder_time' => $time,
            'rules.reminder_hours' => ['required', 'integer', 'min:0', 'max:48'],
            'rules.recap_time' => $time,
            'rules.opens_at' => $time,
            'rules.closes_at' => array_merge($time, ['after:rules.opens_at']),
            'rules.days' => ['array'],
            'rules.days.*' => ['integer', 'between:1,7'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ], [], [
            'firm.name' => 'nom du cabinet',
            'firm.email' => 'e-mail du cabinet',
            'rules.vat_rate' => 'taux de TVA',
            'rules.threshold' => 'seuil de validation',
            'rules.consultation_fee' => 'tarif de consultation',
            'rules.reminder_time' => 'heure du rappel',
            'rules.reminder_hours' => 'délai du rappel',
            'rules.recap_time' => 'heure du récapitulatif',
            'rules.opens_at' => 'heure d\'ouverture',
            'rules.closes_at' => 'heure de fermeture',
        ]);

        $values = [
            'firm.name' => $this->firm['name'],
            'firm.bar' => $this->firm['bar'],
            'firm.address' => $this->firm['address'],
            'firm.rccm' => $this->firm['rccm'],
            'firm.id_nat' => $this->firm['id_nat'],
            'firm.nif' => $this->firm['nif'],
            'firm.phone' => $this->firm['phone'],
            'firm.email' => $this->firm['email'],
            'firm.bank_details' => $this->firm['bank_details'],
            'vat.enabled' => (bool) $this->rules['vat_enabled'],
            'vat.rate' => (string) $this->rules['vat_rate'],
            'cash.disbursement_threshold_usd' => (string) $this->rules['threshold'],
            'consultation.default_fee' => (string) $this->rules['consultation_fee'],
            'consultation.default_currency' => $this->rules['consultation_currency'],
            'subscription_auto_issue' => (bool) $this->rules['subscription_auto_issue'],
            'reminders.day_before_time' => $this->rules['reminder_time'],
            'reminders.hours_before' => (int) $this->rules['reminder_hours'],
            'recap.time' => $this->rules['recap_time'],
            'office.opens_at' => $this->rules['opens_at'],
            'office.closes_at' => $this->rules['closes_at'],
            'office.days' => collect($this->rules['days'])->map(fn ($d) => (int) $d)->sort()->values()->all(),
        ];

        if ($this->logo) {
            $values['firm.logo_path'] = $this->logo->storeAs('firms/'.app(\App\Support\Tenancy\CurrentFirm::class)->require(), 'logo.'.$this->logo->extension(), 'local');
            $this->logoPath = $values['firm.logo_path'];
            $this->logo = null;
        }

        $settings->setMany($values, auth()->user());

        session()->flash('status', 'Paramètres enregistrés.');
    }

    public function render(): View
    {
        return view('livewire.admin.firm-settings', [
            'currencies' => Currency::cases(),
            'weekdays' => [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'],
        ]);
    }
}
