<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages des cabinets : réservées aux membres d'un cabinet. Le super administrateur est
 * renvoyé vers l'espace Plateforme ; il n'a accès à aucune donnée métier.
 */
class EnsureFirmMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isSuperAdmin()) {
            return redirect()->route('platform.firms');
        }

        abort_if($user === null || $user->firm_id === null, 403, 'Compte rattaché à aucun cabinet.');

        return $next($request);
    }
}
