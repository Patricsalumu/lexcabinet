<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();     // FAC-2026-00118, attribué à l'émission
            // Nul uniquement pour une consultation facturée depuis l'agenda (FAC-06).
            $table->foreignId('case_id')->nullable()->constrained('cases')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->string('fee_type', 30)->nullable();               // convention du dossier au moment de la facture
            $table->string('status', 20)->default('draft');
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 15, 4)->nullable();      // CDF pour 1 USD, mémorisé à l'émission
            $table->date('issue_date')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(15);
            $table->date('due_date')->nullable();
            $table->date('period_start')->nullable();                 // abonnement, temps passé
            $table->date('period_end')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->boolean('vat_enabled')->default(false);
            $table->decimal('vat_rate', 5, 2)->default(0);
            // Totaux recalculés (bcmath) à chaque modification du brouillon, figés à l'émission.
            $table->decimal('fees_total', 15, 2)->default(0);        // honoraires avant réduction
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('other_total', 15, 2)->default(0);       // débours et frais
            $table->decimal('taxable_base', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);             // TTC
            $table->decimal('paid_amount', 15, 2)->default(0);       // encaissements (phase 5)
            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index(['case_id', 'period_start']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('origin', 10);                 // auto | manual
            $table->string('type', 20);                   // fee | consultation | disbursement | court_fee | transport | file_fee
            $table->string('label');
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->boolean('taxable');
            $table->json('time_entry_ids')->nullable();   // heures reprises par une ligne au taux horaire
            $table->timestamps();
        });

        // Avoirs (FAC-09) : annulation d'une facture émise, numérotés AV-AAAA-00001.
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 15, 4);
            $table->date('issue_date');
            $table->decimal('fees_amount', 15, 2);        // honoraires nets (706)
            $table->decimal('other_amount', 15, 2);       // débours et frais
            $table->decimal('vat_amount', 15, 2);         // 4431
            $table->decimal('total', 15, 2);              // 411
            $table->text('reason');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Liens « facturé » prévus en phases 2 et 3.
        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropForeign(['invoice_id']));
        Schema::table('time_entries', fn (Blueprint $table) => $table->dropForeign(['invoice_id']));
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
