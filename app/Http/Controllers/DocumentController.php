<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\LegalCase;
use App\Services\DocumentService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargement d'une pièce jointe depuis le disque privé, après contrôle des droits.
 */
class DocumentController extends Controller
{
    public function __invoke(LegalCase $case, Document $document): StreamedResponse
    {
        abort_unless($document->case_id === $case->id, 404);
        Gate::authorize('view', $document);

        abort_unless(Storage::disk(DocumentService::DISK)->exists($document->path), 404);

        activity('documents')->causedBy(auth()->user())->performedOn($document)->log('Pièce jointe consultée');

        return Storage::disk(DocumentService::DISK)->download($document->path, $document->original_name);
    }
}
