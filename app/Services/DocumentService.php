<?php

namespace App\Services;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pièces jointes (DOS-06) : disque privé « local », jamais dans public/.
 */
class DocumentService
{
    public const DISK = 'local';

    /** 20 Mo par fichier. */
    public const MAX_KILOBYTES = 20480;

    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];

    public function store(LegalCase $case, UploadedFile $file, DocumentCategory $category, User $by): Document
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->storeAs("cases/{$case->id}", Str::uuid().'.'.$extension, self::DISK);

        try {
            return DB::transaction(fn () => $case->documents()->create([
                'category' => $category,
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'path' => $path,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
                'uploaded_by' => $by->id,
            ]));
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }
    }
}
