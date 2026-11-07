<?php

namespace App\Livewire\Admin;

use App\Enums\RateSource;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Taux de change journaliers (CAI-07), saisis par l'administrateur.
 */
#[Layout('layouts.app')]
#[Title('Taux de change')]
class ExchangeRates extends Component
{
    use WithPagination;

    public string $rateDate = '';

    public string $rate = '';

    public string $source = 'manual';

    public function mount(ExchangeRateService $rates): void
    {
        $this->authorize('viewAny', ExchangeRate::class);

        $this->rateDate = today()->toDateString();
        $current = $rates->rateFor();
        $this->rate = $current ? rtrim(rtrim((string) $current->rate, '0'), '.') : '';
        $this->source = $current?->source->value ?? RateSource::Manual->value;
    }

    public function save(ExchangeRateService $rates): void
    {
        $this->authorize('create', ExchangeRate::class);

        $this->validate([
            'rateDate' => ['required', 'date', 'before_or_equal:today'],
            'rate' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999'],
            'source' => ['required', Rule::enum(RateSource::class)],
        ], [], ['rateDate' => 'date', 'rate' => 'taux', 'source' => 'source']);

        try {
            $rates->record($this->rateDate, $this->rate, RateSource::from($this->source), auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->addError('rate', $e->getMessage());

            return;
        }

        session()->flash('status', 'Taux enregistré.');
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.exchange-rates', [
            'rates' => ExchangeRate::query()->with('createdBy')->orderByDesc('rate_date')->paginate(15),
            'sources' => RateSource::options(),
        ]);
    }
}
