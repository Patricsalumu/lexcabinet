<?php

namespace App\Livewire;

use App\Enums\Module;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Page d'attente des modules livrés dans une phase ultérieure.
 * L'accès est déjà contrôlé par la gate « view-module » sur la route.
 */
#[Layout('layouts.app')]
class ModulePlaceholder extends Component
{
    public string $module;

    public function mount(string $module): void
    {
        $this->module = Module::from($module)->value;
    }

    public function render(): View
    {
        $module = Module::from($this->module);

        return view('livewire.module-placeholder', ['moduleEnum' => $module])
            ->title($module->label());
    }
}
