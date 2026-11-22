<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations par e-mail : chaque envoi reçoit un Message-ID propre, qui permet de rattacher
 * les réponses des destinataires (lues dans la boîte IMAP du cabinet) à l'e-mail d'origine.
 * Migration purement additive : aucune donnée existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('message_id', 191)->nullable()->unique()->after('template_key');
            // Premier e-mail de la conversation ; nul pour un e-mail qui ouvre lui-même la conversation.
            $table->foreignId('thread_id')->nullable()->after('message_id')->constrained('email_logs')->nullOnDelete();
            $table->string('in_reply_to', 191)->nullable()->after('thread_id');
            $table->text('references')->nullable()->after('in_reply_to');
        });

        // Réponses reçues (copie : le message reste intact dans la boîte aux lettres).
        Schema::create('email_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_log_id')->constrained('email_logs');      // e-mail auquel le correspondant répond
            $table->foreignId('thread_id')->constrained('email_logs');         // conversation
            $table->string('message_id', 191)->unique();
            $table->string('in_reply_to', 191)->nullable();
            $table->text('references')->nullable();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->nullableMorphs('sender');                                  // client ou utilisateur reconnu
            $table->string('subject');
            $table->longText('body');
            $table->json('attachments')->nullable();                           // [{name, path, size, mime}]
            $table->string('folder', 100);
            $table->timestamp('received_at');
            $table->timestamp('read_at')->nullable();
            $table->foreignId('read_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['thread_id', 'received_at']);
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_replies');

        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('thread_id');
            $table->dropUnique(['message_id']);
            $table->dropColumn(['message_id', 'in_reply_to', 'references']);
        });
    }
};
