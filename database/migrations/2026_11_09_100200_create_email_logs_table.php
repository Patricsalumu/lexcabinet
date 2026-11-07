<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal d'envoi (NOT-07) : chaque e-mail, envoyé ou non, avec son motif.
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 40);
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->json('attachments')->nullable();       // ex. [{"type":"ics","method":"REQUEST"}]
            $table->string('status', 20);                  // queued | sent | failed | not_sent
            $table->text('reason')->nullable();            // motif du non-envoi ou erreur
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->nullableMorphs('recipient');           // client ou utilisateur destinataire
            $table->nullableMorphs('related');             // rendez-vous, facture, utilisateur (récapitulatif)…
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resent_from_id')->nullable()->constrained('email_logs')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['template_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
