<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();     // LEX-2026-0042
            $table->string('title');
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('adverse_party')->nullable();
            $table->foreignId('lead_lawyer_id')->constrained('users')->restrictOnDelete();
            $table->string('matter', 30);
            $table->string('jurisdiction')->nullable();
            $table->string('docket_number', 60)->nullable(); // n° de rôle (RG)
            $table->string('status', 20)->default('open');
            // Convention d'honoraires (DOS-04)
            $table->string('fee_type', 30);
            $table->decimal('fee_amount', 15, 2);
            $table->string('currency', 3);
            // DOS-08 : abonnement porté par le dossier
            $table->date('opened_on');
            $table->date('next_subscription_invoice_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('adverse_party');
        });

        // Collaborateurs du dossier (avocats autres que le responsable).
        Schema::create('case_user', function (Blueprint $table) {
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['case_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_user');
        Schema::dropIfExists('cases');
    }
};
