<?php

namespace App\Http\Controllers;

use App\Services\EsiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function __construct(protected EsiService $esi) {}

    /** Delete the current account and revoke its ESI tokens. @status 204 */
    public function destroy(Request $request): RedirectResponse|Response
    {
        $user = $request->user();

        foreach ($user->characters as $character) {
            if ($character->refresh_token) {
                $this->esi->revokeToken($character->refresh_token);
            }
        }

        $user->delete();

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $request->expectsJson()
            ? response()->noContent()
            : redirect()->route('home')->with('success', 'Your account has been permanently deleted.');
    }
}
