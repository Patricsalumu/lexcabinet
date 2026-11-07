<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /** Une pièce se consulte avec les mêmes droits que son dossier. */
    public function view(User $user, Document $document): bool
    {
        return $user->can('view', $document->legalCase);
    }

    public function delete(User $user, Document $document): bool
    {
        return false;
    }
}
