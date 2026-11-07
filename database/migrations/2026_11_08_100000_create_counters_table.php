<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Compteurs de numérotation continue (dossiers, factures, reçus), verrouillés par lockForUpdate.
        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->unsignedInteger('value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counters');
    }
};
