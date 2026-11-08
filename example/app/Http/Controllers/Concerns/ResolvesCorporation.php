<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Character;
use App\Models\SdeSolarSystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait ResolvesCorporation
{
    /**
     * Resolve the main character for the currently authenticated user,
     * falling back to any character on the account.
     */
    protected function getUserCharacter(): ?Character
    {
        $userId = auth()->id();
        if (! $userId) {
            return null;
        }

        return Character::where('user_id', $userId)
            ->where('is_main', true)
            ->first()
            ?? Character::where('user_id', $userId)->first();
    }

    protected function getUserCorporationId(): ?int
    {
        return $this->getUserCharacter()?->corporation_id;
    }

    /**
     * Shared solar-system typeahead search backed by the SDE.
     */
    protected function solarSystemSearch(Request $request): JsonResponse
    {
        $query = (string) $request->input('q', '');
        if (strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $systems = SdeSolarSystem::where('solar_system_name', 'like', '%'.$escaped.'%')
            ->orderBy('solar_system_name')
            ->limit(20)
            ->get(['solar_system_id', 'solar_system_name', 'security']);

        return response()->json([
            'results' => $systems->map(fn (SdeSolarSystem $s) => [
                'solar_system_id' => $s->solar_system_id,
                'solar_system_name' => $s->solar_system_name,
                'security' => round($s->security ?? 0, 1),
            ])->values()->toArray(),
        ]);
    }
}
