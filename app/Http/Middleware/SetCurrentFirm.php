<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentFirm;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Place la requête dans le cabinet de l'utilisateur connecté (toutes les requêtes web,
 * y compris les mises à jour Livewire). Sans utilisateur, aucun cabinet : les données
 * métier restent inaccessibles.
 */
class SetCurrentFirm
{
    public function __construct(private readonly CurrentFirm $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->current->set($request->user()?->firm_id);

        return $next($request);
    }
}
