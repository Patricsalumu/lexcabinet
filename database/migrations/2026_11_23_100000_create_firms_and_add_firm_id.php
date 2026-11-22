<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-cabinet (phase M1) : chaque donnée métier appartient à un cabinet.
 *
 * Sur une installation existante, toutes les données deviennent celles du cabinet n° 1
 * (nommé d'après le paramètre « firm.name ») ; rien n'est supprimé. Les numérotations et
 * les clés uniques deviennent propres à chaque cabinet.
 */
return new class extends Migration
{
    /** Tables métier : firm_id obligatoire. */
    private const TABLES = [
        'settings', 'exchange_rates', 'counters', 'clients', 'cases', 'case_events', 'time_entries', 'documents',
        'appointments', 'email_templates', 'email_logs', 'email_replies', 'invoices', 'invoice_lines', 'credit_notes',
        'treasury_accounts', 'cash_sessions', 'cash_movements', 'advance_allocations', 'accounts', 'accounting_periods',
        'journal_entries', 'journal_lines',
    ];

    /** Tables où firm_id peut être nul : super administrateur, actions de la plateforme. */
    private const OPTIONAL_TABLES = ['users', 'activity_log'];

    /** Clés uniques globales qui deviennent uniques par cabinet : [table, ancien index, colonnes]. */
    private const UNIQUES = [
        ['settings', 'settings_key_unique', ['key']],
        ['exchange_rates', 'exchange_rates_rate_date_unique', ['rate_date']],
        ['counters', 'counters_key_unique', ['key']],
        ['email_templates', 'email_templates_key_unique', ['key']],
        ['cases', 'cases_reference_unique', ['reference']],
        ['invoices', 'invoices_number_unique', ['number']],
        ['credit_notes', 'credit_notes_number_unique', ['number']],
        ['treasury_accounts', 'treasury_accounts_type_currency_name_unique', ['type', 'currency', 'name']],
        ['cash_movements', 'cash_movements_receipt_number_unique', ['receipt_number']],
        ['accounts', 'accounts_code_unique', ['code']],
        ['accounting_periods', 'accounting_periods_year_month_unique', ['year', 'month']],
        ['journal_entries', 'journal_entries_number_unique', ['number']],
    ];

    public function up(): void
    {
        Schema::create('firms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $firmId = $this->createExistingFirm();

        foreach ([...self::TABLES, ...self::OPTIONAL_TABLES] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('firm_id')->nullable()->after('id');
            });

            if ($firmId !== null) {
                DB::table($name)->whereNull('firm_id')->update(['firm_id' => $firmId]);
            }
        }

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('firm_id')->nullable(false)->change();
            });
        }

        foreach ([...self::TABLES, ...self::OPTIONAL_TABLES] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreign('firm_id')->references('id')->on('firms')->restrictOnDelete();
            });
        }

        foreach (self::UNIQUES as [$name, $index, $columns]) {
            Schema::table($name, function (Blueprint $table) use ($index, $columns) {
                $table->dropUnique($index);
                $table->unique(['firm_id', ...$columns]);
            });
        }
    }

    public function down(): void
    {
        foreach ([...self::TABLES, ...self::OPTIONAL_TABLES] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['firm_id']);
            });
        }

        foreach (self::UNIQUES as [$name, $index, $columns]) {
            Schema::table($name, function (Blueprint $table) use ($index, $columns) {
                $table->dropUnique(['firm_id', ...$columns]);
                $table->unique($columns, $index);
            });
        }

        foreach ([...self::TABLES, ...self::OPTIONAL_TABLES] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('firm_id');
            });
        }

        Schema::dropIfExists('firms');
    }

    /** Installation existante : ses données deviennent le cabinet n° 1. Base vide : aucun cabinet créé. */
    private function createExistingFirm(): ?int
    {
        $hasData = DB::table('users')->exists() || DB::table('settings')->exists() || DB::table('clients')->exists();
        if (! $hasData) {
            return null;
        }

        $name = DB::table('settings')->where('key', 'firm.name')->value('value');

        return DB::table('firms')->insertGetId([
            'name' => filled($name) ? $name : 'Cabinet',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
