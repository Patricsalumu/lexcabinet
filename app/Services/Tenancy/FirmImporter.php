<?php

namespace App\Services\Tenancy;

use App\Models\User;
use App\Support\Tenancy\CurrentFirm;
use App\Support\Tenancy\FirmTables;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use ZipArchive;

/**
 * Import d'un cabinet exporté par FirmExporter, sur une installation qui ne contient pas déjà
 * ses données (en pratique : le serveur propre du cabinet, juste après l'installation).
 *
 * Tout est vérifié avant d'écrire quoi que ce soit : mot de passe, intégrité de chaque fichier,
 * version du schéma, absence de conflit d'identifiants, d'adresses e-mail et de fichiers.
 * Les identifiants d'origine sont conservés (numéros, liens entre les données). L'écriture se fait
 * dans une seule transaction : en cas d'erreur, rien n'est importé.
 */
class FirmImporter
{
    /** @var array<string, mixed> */
    private array $manifest = [];

    private ZipArchive $zip;

    /**
     * Vérifie l'archive sans rien écrire. Renvoie la liste des problèmes (vide si l'import est possible).
     *
     * @return list<string>
     */
    public function check(string $archive, string $password): array
    {
        $this->open($archive, $password);
        $problems = [];

        if (($this->manifest['format_version'] ?? null) !== FirmExporter::FORMAT_VERSION) {
            return ['Format d\'archive non reconnu (version '.($this->manifest['format_version'] ?? '?').').'];
        }

        foreach ($this->manifest['checksums'] as $name => $hash) {
            $content = $this->zip->getFromName($name);
            if ($content === false || hash('sha256', $content) !== $hash) {
                $problems[] = "Fichier de données altéré ou illisible : {$name}.";
            }
        }
        foreach ($this->manifest['files'] as $path => $hash) {
            $content = $this->zip->getFromName('files/'.$path);
            if ($content === false || hash('sha256', $content) !== $hash) {
                $problems[] = "Pièce altérée ou illisible : {$path}.";
            }
        }

        $missing = array_diff($this->manifest['migrations'], DB::table('migrations')->pluck('migration')->all());
        if ($missing !== []) {
            $problems[] = 'Le schéma de cette installation est plus ancien que celui de l\'archive : mettez le code à jour puis lancez « php artisan migrate ». Migrations manquantes : '.implode(', ', $missing).'.';
        }
        if ($problems !== []) {
            return $problems;
        }

        $firmId = $this->manifest['firm']['id'];
        if (DB::table('firms')->where('id', $firmId)->exists()) {
            $problems[] = "Un cabinet n° {$firmId} existe déjà sur cette installation.";
        }

        foreach (FirmTables::TABLES as $table) {
            $ids = array_column($this->rows($table), 'id');
            foreach (array_chunk($ids, 1000) as $chunk) {
                if ($conflicts = DB::table($table)->whereIn('id', $chunk)->count()) {
                    $problems[] = "Table {$table} : {$conflicts} identifiant(s) déjà utilisé(s) sur cette installation.";
                    break;
                }
            }
        }

        $emails = array_column($this->rows('users'), 'email');
        if ($taken = DB::table('users')->whereIn('email', $emails)->pluck('email')->all()) {
            $problems[] = 'Adresse(s) e-mail déjà utilisée(s) par un compte de cette installation : '.implode(', ', $taken).'.';
        }

        foreach (array_keys($this->manifest['files']) as $path) {
            if (Storage::disk(FirmExporter::DISK)->exists($path)) {
                $problems[] = "Le fichier {$path} existe déjà sur cette installation.";
            }
        }

        return $problems;
    }

    /**
     * Importe le cabinet. Lève une exception (et n'écrit rien) si la vérification échoue.
     *
     * @return array{firm: array<string, mixed>, counts: array<string, int>}
     */
    public function import(string $archive, string $password): array
    {
        $problems = $this->check($archive, $password);
        if ($problems !== []) {
            throw new RuntimeException(implode("\n", $problems));
        }

        if (DB::table('roles')->doesntExist()) {
            Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
        }

        $firm = $this->manifest['firm'];
        $counts = [];
        $written = [];

        try {
            DB::transaction(function () use ($firm, &$counts, &$written) {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');

                try {
                    DB::table('firms')->insert([
                        'id' => $firm['id'], 'name' => $firm['name'], 'is_active' => $firm['is_active'],
                        'created_at' => $firm['created_at'], 'updated_at' => now(),
                    ]);

                    foreach ([...FirmTables::TABLES, 'case_user'] as $table) {
                        $rows = $this->rows($table);
                        foreach (array_chunk($rows, 500) as $chunk) {
                            DB::table($table)->insert($chunk);
                        }
                        $counts[$table] = count($rows);
                    }

                    $roleIds = DB::table('roles')->where('guard_name', 'web')->pluck('id', 'name');
                    $assignments = [];
                    foreach ($this->rows('roles') as $row) {
                        if (! isset($roleIds[$row['role']])) {
                            throw new RuntimeException("Rôle inconnu sur cette installation : {$row['role']}.");
                        }
                        $assignments[] = ['role_id' => $roleIds[$row['role']], 'model_type' => (new User)->getMorphClass(), 'model_id' => $row['user_id']];
                    }
                    DB::table('model_has_roles')->insert($assignments);
                    $counts['roles'] = count($assignments);
                } finally {
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                }

                foreach (array_keys($this->manifest['files']) as $path) {
                    Storage::disk(FirmExporter::DISK)->put($path, $this->zip->getFromName('files/'.$path));
                    $written[] = $path;
                }

                activity('platform')->withProperties(['firm' => $firm['name'], 'exported_at' => $this->manifest['exported_at']])
                    ->log('Cabinet importé depuis une archive');
            });
        } catch (\Throwable $e) {
            // Transaction annulée : on retire aussi les fichiers déjà déposés.
            foreach ($written as $path) {
                Storage::disk(FirmExporter::DISK)->delete($path);
            }
            throw $e;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(CurrentFirm::class)->run((int) $firm['id'], fn () => Cache::forget('lexcabinet.settings.'.$firm['id']));
        $this->zip->close();

        return ['firm' => $firm, 'counts' => $counts + ['files' => count($this->manifest['files'])]];
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    private function open(string $archive, string $password): void
    {
        if (! is_file($archive)) {
            throw new RuntimeException("Archive introuvable : {$archive}");
        }

        $this->zip = new ZipArchive;
        if ($this->zip->open($archive) !== true) {
            throw new RuntimeException('Archive illisible (fichier ZIP invalide).');
        }
        $this->zip->setPassword($password);

        $manifest = $this->zip->getFromName('manifest.json');
        if ($manifest === false || ! is_array($decoded = json_decode($manifest, true))) {
            throw new RuntimeException('Mot de passe incorrect, ou archive qui n\'a pas été produite par LexCabinet.');
        }
        $this->manifest = $decoded;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table): array
    {
        $content = $this->zip->getFromName("data/{$table}.jsonl");
        if ($content === false || $content === '') {
            return [];
        }

        return array_map(fn (string $line) => json_decode($line, true), array_values(array_filter(explode("\n", $content), fn ($l) => $l !== '')));
    }
}
