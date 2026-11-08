<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Services\CharacterManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CharacterManagementController extends Controller
{
    /** Set one of your linked characters as main. @status 204 */
    public function setMain(Request $request, Character $character, CharacterManagementService $characters): Response
    {
        $characters->setMain($request->user(), $character);

        return response()->noContent();
    }

    /** Remove a linked character, keeping at least one character. @status 204 */
    public function destroy(Request $request, Character $character, CharacterManagementService $characters): Response
    {
        $characters->remove($request->user(), $character);

        return response()->noContent();
    }
}
