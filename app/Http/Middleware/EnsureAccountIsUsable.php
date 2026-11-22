<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Déconnecte un utilisateur désactivé, verrouillé, ou dont le cabinet est désactivé, pendant sa session.
 */
class EnsureAccountIsUsable
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $firmInactive = $user && $user->firm_id !== null && ! $user->firm?->is_active;

        if ($user && (! $user->is_active || $user->isLocked() || $firmInactive)) {
            $message = match (true) {
                ! $user->is_active => trans('auth.inactive'),
                $firmInactive => trans('auth.firm_inactive'),
                default => trans('auth.locked'),
            };

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['form.email' => $message]);
        }

        return $next($request);
    }
}
