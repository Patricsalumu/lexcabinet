<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('worked_on');
            $table->unsignedSmallInteger('minutes');
            $table->text('description');
            $table->boolean('billable')->default(true);
            // Renseigné à l'émission de la facture (phase 4) ; la clé étrangère sera ajoutée avec la table invoices.
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->timestamps();

            $table->index(['case_id', 'billable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
