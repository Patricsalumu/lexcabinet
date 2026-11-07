<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal du dossier (DOS-05) : actes, audiences, notes.
        Schema::create('case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->string('type', 20);                 // act | hearing | note
            $table->dateTime('occurred_at');
            $table->text('description');
            $table->string('chamber', 100)->nullable(); // audiences (RDV-06)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_events');
    }
};
