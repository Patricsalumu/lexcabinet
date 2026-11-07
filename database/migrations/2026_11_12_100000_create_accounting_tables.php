<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plan comptable inspiré du SYSCOHADA révisé (CPT-01).
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('label');
            $table->unsignedTinyInteger('class');          // 1 à 7
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Périodes mensuelles ; une période clôturée n'accepte plus d'écriture (CPT-06).
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 10)->default('open');   // open | closed
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['year', 'month']);
        });

        // Écritures (CPT-02 à CPT-04) : en USD, montant et taux d'origine conservés. Jamais modifiées.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();          // VT-2026-00001
            $table->string('journal', 2);                    // VT | CA | BQ | OD
            $table->date('entry_date');
            $table->string('label');
            $table->string('reference')->nullable();         // pièce : FAC-…, RC-…, AV-…
            $table->nullableMorphs('source');                // facture, avoir, mouvement de caisse, imputation
            $table->string('source_event', 20)->nullable();  // issue | cancel | movement | allocation
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->boolean('is_manual')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entry_date', 'journal']);
            $table->unique(['source_type', 'source_id', 'source_event']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('label')->nullable();
            $table->decimal('debit', 15, 2)->default(0);     // USD
            $table->decimal('credit', 15, 2)->default(0);    // USD
            $table->string('original_currency', 3);
            $table->decimal('original_amount', 15, 2);
            $table->decimal('exchange_rate', 15, 4);
            $table->timestamps();

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('accounts');
    }
};
