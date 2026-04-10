<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class ImpersonateController extends Controller
{
    public function impersonate(User $user): RedirectResponse
    {
        $currentUser = Auth::user();

        if (! $currentUser || ! $currentUser->canAccessPanel(app(\Filament\Panel::class))) {
            abort(403);
        }

        Session::put('impersonator_id', $currentUser->id);
        Auth::loginUsingId($user->id);

        return redirect('/admin');
    }

    /**
     * Impersonate into SPA — creates Sanctum token and redirects with it.
     */
    public function impersonateSpa(User $user): RedirectResponse
    {
        $currentUser = Auth::user();

        if (! $currentUser || ! $currentUser->canAccessPanel(app(\Filament\Panel::class))) {
            abort(403);
        }

        Session::put('impersonator_id', $currentUser->id);

        // Create Sanctum token for SPA
        $token = $user->createToken('impersonate')->plainTextToken;

        return redirect('/?impersonate_token=' . $token);
    }

    public function leave(): RedirectResponse
    {
        $impersonatorId = Session::pull('impersonator_id');

        if (! $impersonatorId) {
            return redirect('/admin');
        }

        Auth::loginUsingId($impersonatorId);

        return redirect('/admin');
    }
}
