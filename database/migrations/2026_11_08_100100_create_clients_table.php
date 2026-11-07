<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);                    // individual | company
            $table->string('name');                        // nom ou raison sociale
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            // Personne physique
            $table->string('id_document_type', 30)->nullable();
            $table->string('id_document_number', 60)->nullable();
            // Personne morale
            $table->string('rccm', 60)->nullable();
            $table->string('id_nat', 60)->nullable();
            $table->string('representative')->nullable();
            // CLI-03
            $table->boolean('email_consent')->default(false);
            $table->boolean('is_prospect')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
