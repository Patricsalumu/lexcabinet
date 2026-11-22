<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coordonnées de paiement communiquées aux clients (signature des e-mails, factures).
     * Ajout de colonnes uniquement : aucune donnée existante n'est modifiée.
     */
    public function up(): void
    {
        Schema::table('treasury_accounts', function (Blueprint $table) {
            $table->string('holder_name')->nullable()->after('name');           // intitulé du compte
            $table->string('account_number', 100)->nullable()->after('holder_name'); // n° de compte, IBAN ou n° mobile money
            $table->string('payment_details')->nullable()->after('account_number'); // SWIFT, agence, horaires…
            $table->boolean('show_to_clients')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_accounts', function (Blueprint $table) {
            $table->dropColumn(['holder_name', 'account_number', 'payment_details', 'show_to_clients']);
        });
    }
};
