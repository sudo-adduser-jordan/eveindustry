<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Services\CharacterManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CharacterController extends Controller
{
    public function setMain(Request $request, Character $character, CharacterManagementService $characters): RedirectResponse
    {
        if ($character->user_id !== $request->user()->id) {
            abort(403);
        }

        $characters->setMain($request->user(), $character);

        return back()->with('success', "{$character->name} is now your main character.");
    }

    public function destroy(Request $request, Character $character, CharacterManagementService $characters): RedirectResponse
    {
        if ($character->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($request->user()->characters()->count() <= 1) {
            return back()->with('error', 'Cannot remove your only character.');
        }

        $name = $character->name;
        $characters->remove($request->user(), $character);

        return back()->with('success', "{$name} removed from your account.");
    }
}
