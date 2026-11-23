<?php

namespace App\Console\Commands;

use App\Services\Tenancy\FirmImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importe un cabinet exporté par « lexcabinet:export-firm » sur cette installation (serveur
 * propre du cabinet). Avec --dry-run, vérifie seulement l'archive et l'installation.
 */
#[Signature('lexcabinet:import-firm {archive : Chemin de l\'archive} {--dry-run : Vérifier sans rien importer} {--password= : Mot de passe de l\'archive (sinon demandé, saisie masquée)}')]
#[Description('Importe un cabinet depuis une archive chiffrée')]
class ImportFirm extends Command
{
    public function handle(FirmImporter $importer): int
    {
        $archive = (string) $this->argument('archive');
        $password = (string) ($this->option('password') ?? $this->secret('Mot de passe de l\'archive'));

        try {
            $problems = $importer->check($archive, $password);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $manifest = $importer->manifest();
        $this->components->twoColumnDetail('Cabinet', $manifest['firm']['name'].' (n° '.$manifest['firm']['id'].')');
        $this->components->twoColumnDetail('Exporté le', (string) $manifest['exported_at']);

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Archive valide : l\'import est possible sur cette installation. Rien n\'a été importé.');

            return self::SUCCESS;
        }

        try {
            $result = $importer->import($archive, $password);
        } catch (Throwable $e) {
            $this->components->error('Import annulé, rien n\'a été écrit : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Cabinet importé : '.$result['firm']['name']);
        foreach ($result['counts'] as $table => $count) {
            if ($count > 0) {
                $this->components->twoColumnDetail($table, (string) $count);
            }
        }
        $this->newLine();
        foreach ($manifest['not_exported'] as $note) {
            $this->components->warn($note);
        }

        return self::SUCCESS;
    }
}
