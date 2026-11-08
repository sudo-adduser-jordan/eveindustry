<?php

namespace App\Services;

use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CharacterManagementService
{
    public function setMain(User $user, Character $character): void
    {
        abort_unless($character->user_id === $user->id, 403);
        DB::transaction(function () use ($user, $character) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->characters()->update(['is_main' => false]);
            $character->update(['is_main' => true]);
        });
    }

    public function remove(User $user, Character $character): void
    {
        abort_unless($character->user_id === $user->id, 403);
        DB::transaction(function () use ($user, $character) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->characters()->count() <= 1) {
                throw ValidationException::withMessages(['character' => 'Cannot remove your only character.']);
            }
            $wasMain = $character->is_main;
            $character->delete();
            if ($wasMain) {
                $user->characters()->first()?->update(['is_main' => true]);
            }
        });
    }
}
