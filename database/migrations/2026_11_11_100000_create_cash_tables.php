<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comptes de trésorerie (CAI-01) : caisse USD, caisse CDF, banques, mobile money.
        Schema::create('treasury_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);                 // cash | bank | mobile_money
            $table->string('currency', 3);
            $table->string('ledger_code', 10);          // 5711, 5712, 521, 5216…
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'currency', 'name']);
        });

        // Sessions de la caisse physique (CAI-02).
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('status', 10)->default('open');      // open | closed
            $table->timestamp('opened_at');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->decimal('opening_usd', 15, 2);               // fonds comptés à l'ouverture
            $table->decimal('opening_cdf', 15, 2);
            $table->decimal('book_opening_usd', 15, 2);          // solde théorique à l'ouverture
            $table->decimal('book_opening_cdf', 15, 2);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('expected_usd', 15, 2)->nullable(); // solde théorique à la clôture
            $table->decimal('expected_cdf', 15, 2)->nullable();
            $table->decimal('counted_usd', 15, 2)->nullable();
            $table->decimal('counted_cdf', 15, 2)->nullable();
            $table->decimal('difference_usd', 15, 2)->nullable();
            $table->decimal('difference_cdf', 15, 2)->nullable();
            $table->text('difference_reason')->nullable();
            $table->timestamps();
        });

        // Mouvements de trésorerie (CAI-03 à CAI-08). Jamais supprimés : correction par contre-passation.
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);           // receipt | disbursement | exchange | adjustment | opening_balance | reversal
            $table->string('direction', 3);       // in | out (sur le compte principal)
            $table->string('status', 10)->default('validated');   // validated | pending | rejected
            $table->dateTime('occurred_at');
            $table->foreignId('cash_session_id')->nullable()->constrained('cash_sessions')->nullOnDelete();
            $table->foreignId('treasury_account_id')->constrained('treasury_accounts')->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('amount', 15, 2);
            $table->decimal('exchange_rate', 15, 4);             // CDF pour 1 USD, mémorisé sur l'opération
            // Change interne (un mouvement : montant cédé et montant reçu).
            $table->foreignId('counter_account_id')->nullable()->constrained('treasury_accounts')->restrictOnDelete();
            $table->string('counter_currency', 3)->nullable();
            $table->decimal('counter_amount', 15, 2)->nullable();
            // Encaissement
            $table->string('mode', 20)->nullable();              // cash | transfer | cheque | mobile_money
            $table->string('reference')->nullable();
            $table->string('receipt_number', 20)->nullable()->unique();   // RC-2026-0310
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->decimal('invoice_amount', 15, 2)->nullable();          // part imputée, en devise de la facture
            // Décaissement
            $table->string('category', 30)->nullable();
            $table->string('beneficiary')->nullable();
            $table->string('voucher')->nullable();                         // pièce justificative
            $table->string('label');
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['treasury_account_id', 'status']);
            $table->index(['type', 'occurred_at']);
        });

        // Imputation d'une provision reçue sans facture sur une facture du même client (FAC-13).
        Schema::create('advance_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_movement_id')->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->decimal('amount', 15, 2);           // en devise de la provision
            $table->decimal('invoice_amount', 15, 2);   // en devise de la facture
            $table->decimal('exchange_rate', 15, 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_allocations');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('treasury_accounts');
    }
};
