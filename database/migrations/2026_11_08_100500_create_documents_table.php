<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pièces jointes des dossiers (DOS-06), stockées sur le disque privé.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->restrictOnDelete();
            $table->string('category', 30);
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 120);
            $table->unsignedInteger('size');            // octets
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
