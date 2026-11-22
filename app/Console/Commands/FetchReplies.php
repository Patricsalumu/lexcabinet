<?php

namespace App\Console\Commands;

use App\Services\ConversationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('lexcabinet:fetch-replies')]
#[Description('Relève les réponses aux e-mails de l\'application dans la boîte IMAP du cabinet (lecture seule)')]
class FetchReplies extends Command
{
    public function handle(ConversationService $conversations): int
    {
        try {
            $count = $conversations->fetch();
        } catch (Throwable $e) {
            $this->components->error('Relève impossible : '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        if ($count === null) {
            $this->components->warn('Boîte IMAP non configurée (IMAP_HOST, IMAP_USERNAME) : aucune relève.');

            return self::SUCCESS;
        }

        $this->components->info("{$count} nouvelle(s) réponse(s) importée(s).");

        return self::SUCCESS;
    }
}
