<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

/**
 * Lien d'accès reçu à la création du compte (signé, 72 h) : génère un jeton de définition
 * du mot de passe et redirige vers la page standard de réinitialisation.
 */
class AccountActivationController extends Controller
{
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        $expired = fn () => redirect()->route('login')->with('status',
            'Ce lien d\'accès n\'est plus valide. Utilisez « Mot de passe oublié ? » pour recevoir un nouveau lien.');

        if (! $request->hasValidSignature()) {
            return $expired();
        }
        if (! $user->is_active || $user->isLocked() || ! hash_equals(AccountNotifier::fingerprint($user), (string) $request->query('h'))) {
            return $expired();
        }

        // Une autre session ouverte dans ce navigateur ne doit pas interférer.
        if (Auth::check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $token = Password::broker()->createToken($user);
        activity('auth')->performedOn($user)->log('Lien d\'accès utilisé pour définir le mot de passe');

        return redirect()->route('password.reset', ['token' => $token, 'email' => $user->email]);
    }
}
