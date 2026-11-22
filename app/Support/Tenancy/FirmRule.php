<?php

namespace App\Support\Tenancy;

use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Rule;

/**
 * Règles de validation limitées au cabinet courant : les règles exists/unique de Laravel
 * interrogent la base directement, sans le filtre Eloquent.
 */
final class FirmRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('firm_id', app(CurrentFirm::class)->require());
    }

    public static function unique(string $table, string $column = 'NULL'): Unique
    {
        return Rule::unique($table, $column)->where('firm_id', app(CurrentFirm::class)->require());
    }
}
