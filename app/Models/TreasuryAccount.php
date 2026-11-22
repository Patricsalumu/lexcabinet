<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\TreasuryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Compte de trésorerie (caisse, banque, mobile money). S'il est « affiché aux clients »,
 * c'est aussi un moyen de paiement communiqué dans les e-mails et sur les factures.
 */
class TreasuryAccount extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name', 'holder_name', 'account_number', 'payment_details', 'type', 'currency',
        'ledger_code', 'is_active', 'show_to_clients',
    ];

    protected function casts(): array
    {
        return [
            'type' => TreasuryType::class,
            'currency' => Currency::class,
            'is_active' => 'boolean',
            'show_to_clients' => 'boolean',
        ];
    }

    public function isCash(): bool
    {
        return $this->type === TreasuryType::Cash;
    }

    /** Moyens de paiement communiqués aux clients : comptes actifs et affichés. */
    public function scopePaymentMethods(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('show_to_clients', true)
            ->orderByRaw("case type when 'bank' then 1 when 'mobile_money' then 2 else 3 end")
            ->orderBy('id');
    }

    /** « Rawbank (USD) — Intitulé : Cabinet Mukendi & Associés — N° 0501-0000000-01 — SWIFT RAWBCDKI » */
    public function paymentLine(): string
    {
        return implode(' — ', array_filter([
            $this->name.' ('.$this->currency->value.')',
            $this->holder_name ? 'Intitulé : '.$this->holder_name : null,
            $this->account_number ? 'N° '.$this->account_number : null,
            $this->payment_details,
        ]));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('cash')
            ->logOnly(['name', 'holder_name', 'account_number', 'payment_details', 'type', 'currency', 'ledger_code', 'is_active', 'show_to_clients'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => 'Compte de trésorerie créé',
                default => 'Compte de trésorerie modifié',
            });
    }
}
