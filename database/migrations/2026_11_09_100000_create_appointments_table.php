<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->foreignId('lawyer_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('type', 20);                       // consultation | follow_up | signing | hearing
            $table->string('location_type', 20);              // office | court | video | client_premises
            $table->string('location_detail')->nullable();    // ex. « Tribunal de commerce, chambre 2 »
            $table->string('subject');
            $table->text('internal_notes')->nullable();
            $table->string('status', 20)->default('planned');
            // Version du fichier .ics (incrémentée à chaque modification envoyée au client).
            $table->unsignedSmallInteger('ics_sequence')->default(0);
            $table->timestamp('reminder_sent_at')->nullable();        // rappel J-1
            $table->timestamp('short_reminder_sent_at')->nullable();  // rappel H-N
            // Facturation de la consultation (RDV-05) : clé étrangère ajoutée en phase 4.
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lawyer_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
