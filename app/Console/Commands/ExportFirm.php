<?php

namespace App\Console\Commands;

use App\Models\Firm;
use App\Services\Tenancy\FirmExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Exporte un cabinet (données et fichiers) dans une archive chiffrée, pour l'installer sur son
 * propre serveur. Commande réservée à l'exploitant du serveur : l'archive contient toutes les
 * données confidentielles du cabinet ; elle est chiffrée par un mot de passe saisi de façon masquée.
 */
#[Signature('lexcabinet:export-firm {firm : Identifiant du cabinet} {--output= : Chemin de l\'archive (par défaut storage/app/exports)} {--password= : Mot de passe de l\'archive (sinon demandé, saisie masquée)}')]
#[Description('Exporte un cabinet dans une archive chiffrée (passage sur son propre serveur)')]
class ExportFirm extends Command
{
    public function handle(FirmExporter $exporter): int
    {
        $firm = Firm::query()->find((int) $this->argument('firm'));
        if ($firm === null) {
            $this->components->error('Cabinet introuvable.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?? $this->secret('Mot de passe de l\'archive ('.FirmExporter::MIN_PASSWORD_LENGTH.' caractères minimum, saisie masquée)'));
        if ($this->option('password') === null && $password !== (string) $this->secret('Confirmez le mot de passe')) {
            $this->components->error('Les deux mots de passe diffèrent.');

            return self::FAILURE;
        }

        try {
            ['path' => $path, 'manifest' => $manifest] = $exporter->export($firm, $password, $this->option('output'));
        } catch (Throwable $e) {
            $this->components->error('Export impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        activity('platform')->performedOn($firm)->withProperties(['archive' => basename($path)])->log('Cabinet exporté (console)');
        // Le cabinet en garde aussi la trace dans son propre journal d'audit.
        app(\App\Support\Tenancy\CurrentFirm::class)->run($firm, fn () => activity('export')
            ->withProperties(['archive' => basename($path)])->log('Données du cabinet exportées pour un transfert de serveur'));

        $this->components->info("Archive chiffrée : {$path}");
        foreach ($manifest['counts'] as $table => $count) {
            if ($count > 0) {
                $this->components->twoColumnDetail($table, (string) $count);
            }
        }
        $this->components->twoColumnDetail('fichiers', (string) count($manifest['files']));
        $this->newLine();
        $this->components->warn('Transmettez l\'archive et son mot de passe par deux canaux différents, puis supprimez l\'archive de ce serveur une fois le transfert vérifié.');

        return self::SUCCESS;
    }
}
