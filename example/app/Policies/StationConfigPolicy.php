<?php

namespace App\Policies;

use App\Models\StationConfig;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A user's structures (station configs).
 *
 * Abilities: view, create, update, delete, setDefault.
 *
 * Only the owner may act on a structure; anyone else gets 404, as the old
 * routes answered.
 */
class StationConfigPolicy
{
    public function view(User $user, StationConfig $structure): Response
    {
        return $this->owns($user, $structure);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, StationConfig $structure): Response
    {
        return $this->owns($user, $structure);
    }

    public function delete(User $user, StationConfig $structure): Response
    {
        return $this->owns($user, $structure);
    }

    public function setDefault(User $user, StationConfig $structure): Response
    {
        return $this->owns($user, $structure);
    }

    private function owns(User $user, StationConfig $structure): Response
    {
        return $user->id === $structure->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
