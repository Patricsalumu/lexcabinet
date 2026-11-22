<?php

namespace App\Http\Controllers;

use App\Models\EmailReply;
use App\Services\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargement d'une pièce jointe reçue dans une conversation, après contrôle des droits.
 */
class ReplyAttachmentController extends Controller
{
    public function __invoke(Request $request, EmailReply $reply, int $index, ConversationService $conversations): StreamedResponse
    {
        abort_unless($conversations->canView($request->user(), $reply->thread), 403);

        $attachment = ($reply->attachments ?? [])[$index] ?? null;
        abort_if($attachment === null || empty($attachment['path']), 404);
        abort_unless(Storage::disk(ConversationService::DISK)->exists($attachment['path']), 404);

        activity('emails')->causedBy($request->user())->performedOn($reply)->log('Pièce jointe d\'une réponse consultée');

        return Storage::disk(ConversationService::DISK)->download($attachment['path'], $attachment['name']);
    }
}
