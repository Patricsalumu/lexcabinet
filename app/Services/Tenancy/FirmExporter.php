<?php

namespace App\Services\Tenancy;

use App\Models\Firm;
use App\Models\User;
use App\Support\Tenancy\FirmTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Export d'un cabinet (phase M4) : toutes ses données et ses fichiers, dans une archive ZIP
 * chiffrée (AES-256) par un mot de passe, pour l'installer sur son propre serveur.
 *
 * Ne sont pas exportés : les mots de passe de messagerie (chiffrés avec la clé de l'application,
 * à ressaisir), les secrets de double authentification (à réactiver) et les jetons de session.
 */
class FirmExporter
{
    public const FORMAT_VERSION = 1;

    public const DISK = 'local';

    public const MIN_PASSWORD_LENGTH = 12;

    /**
     * @return array{path: string, manifest: array<string, mixed>}
     */
    public function export(Firm $firm, string $password, ?string $output = null): array
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Le mot de passe de l\'archive doit compter au moins '.self::MIN_PASSWORD_LENGTH.' caractères.');
        }

        $output ??= storage_path('app/exports/cabinet-'.$firm->id.'-'.now()->format('Ymd-His').'.zip');
        File::ensureDirectoryExists(dirname($output));
        $work = storage_path('app/exports/tmp-'.Str::random(16));
        File::ensureDirectoryExists($work.'/data');

        try {
            $counts = [];
            $checksums = [];

            foreach (FirmTables::TABLES as $table) {
                $counts[$table] = $this->exportTable($table, $firm->id, $work.'/data/'.$table.'.jsonl');
            }
            $counts['case_user'] = $this->writeRows($work.'/data/case_user.jsonl',
                DB::table('case_user')->whereIn('case_id', DB::table('cases')->where('firm_id', $firm->id)->select('id'))->orderBy('case_id')->orderBy('user_id')->get());
            $counts['roles'] = $this->exportRoles($firm->id, $work.'/data/roles.jsonl');

            foreach (File::files($work.'/data') as $file) {
                $checksums['data/'.$file->getFilename()] = hash_file('sha256', $file->getPathname());
            }

            $files = $this->filePaths($firm->id);

            $manifest = [
                'format_version' => self::FORMAT_VERSION,
                'application' => 'LexCabinet',
                'exported_at' => now()->toIso8601String(),
                'firm' => ['id' => $firm->id, 'name' => $firm->name, 'is_active' => $firm->is_active, 'created_at' => $firm->created_at?->toDateTimeString()],
                'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
                'counts' => $counts,
                'files' => [],
                'checksums' => $checksums,
                'not_exported' => [
                    'Mots de passe de messagerie (SMTP, IMAP) : à ressaisir dans Paramètres → Messagerie.',
                    'Double authentification : chaque utilisateur la réactive s\'il le souhaite.',
                ],
            ];

            $zip = new ZipArchive;
            if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Impossible de créer l'archive {$output}.");
            }

            foreach (File::files($work.'/data') as $file) {
                $this->addEncrypted($zip, 'data/'.$file->getFilename(), $file->getPathname(), $password);
            }

            $disk = Storage::disk(self::DISK);
            foreach ($files as $path) {
                $local = $disk->path($path);
                $manifest['files'][$path] = hash_file('sha256', $local);
                $this->addEncrypted($zip, 'files/'.$path, $local, $password);
            }

            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $password);

            if ($zip->close() !== true) {
                throw new RuntimeException("Écriture de l'archive {$output} impossible.");
            }
        } finally {
            File::deleteDirectory($work);
        }

        return ['path' => $output, 'manifest' => $manifest];
    }

    private function exportTable(string $table, int $firmId, string $target): int
    {
        $handle = fopen($target, 'w');
        $count = 0;

        DB::table($table)->where('firm_id', $firmId)->orderBy('id')->chunk(500, function ($rows) use ($handle, $table, &$count) {
            foreach ($rows as $row) {
                fwrite($handle, json_encode($this->sanitize($table, (array) $row), JSON_UNESCAPED_UNICODE)."\n");
                $count++;
            }
        });

        fclose($handle);

        return $count;
    }

    /** Retire ce qui dépend de la clé de l'application ou de la session. */
    private function sanitize(string $table, array $row): array
    {
        if ($table === 'users') {
            $row['two_factor_secret'] = null;
            $row['two_factor_confirmed_at'] = null;
            $row['remember_token'] = null;
        }

        if ($table === 'settings' && ($row['type'] ?? null) === 'secret') {
            $row['value'] = null;
        }

        return $row;
    }

    /** Rôles des utilisateurs, par nom (les identifiants des rôles diffèrent d'une installation à l'autre). */
    private function exportRoles(int $firmId, string $target): int
    {
        $rows = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('model_has_roles.model_id', DB::table('users')->where('firm_id', $firmId)->select('id'))
            ->orderBy('model_has_roles.model_id')
            ->get(['model_has_roles.model_id as user_id', 'roles.name as role']);

        return $this->writeRows($target, $rows);
    }

    private function writeRows(string $target, iterable $rows): int
    {
        $handle = fopen($target, 'w');
        $count = 0;
        foreach ($rows as $row) {
            fwrite($handle, json_encode((array) $row, JSON_UNESCAPED_UNICODE)."\n");
            $count++;
        }
        fclose($handle);

        return $count;
    }

    /**
     * Fichiers du cabinet : son dossier firms/{id}/, plus les fichiers enregistrés avant le
     * multi-cabinet (pièces des dossiers, logo, pièces jointes reçues) à leur ancien emplacement.
     *
     * @return list<string>
     */
    private function filePaths(int $firmId): array
    {
        $disk = Storage::disk(self::DISK);
        $paths = $disk->allFiles("firms/{$firmId}");

        $paths = array_merge($paths, DB::table('documents')->where('firm_id', $firmId)->pluck('path')->all());

        $logo = DB::table('settings')->where('firm_id', $firmId)->where('key', 'firm.logo_path')->value('value');
        if ($logo) {
            $paths[] = $logo;
        }

        foreach (DB::table('email_replies')->where('firm_id', $firmId)->whereNotNull('attachments')->pluck('attachments') as $json) {
            foreach (json_decode((string) $json, true) ?: [] as $attachment) {
                if (! empty($attachment['path'])) {
                    $paths[] = $attachment['path'];
                }
            }
        }

        return array_values(array_unique(array_filter($paths, fn ($p) => $disk->exists($p))));
    }

    private function addEncrypted(ZipArchive $zip, string $name, string $localPath, string $password): void
    {
        $zip->addFile($localPath, $name);
        $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
    }
}
