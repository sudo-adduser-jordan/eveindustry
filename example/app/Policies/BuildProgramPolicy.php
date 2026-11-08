<?php

namespace App\Policies;

use App\Models\BuildProgram;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A user's own (personal) build programs.
 *
 * Abilities: view, create, update, delete, merge.
 *
 * view/update/delete only for the owner; anyone else gets 404 (as the old
 * routes did) rather than 403, so program ids are not probed. create and
 * merge are open to every logged-in user: merge only ever reads the user's
 * own programs.
 */
class BuildProgramPolicy
{
    public function view(User $user, BuildProgram $program): Response
    {
        return $this->owns($user, $program);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, BuildProgram $program): Response
    {
        return $this->owns($user, $program);
    }

    public function delete(User $user, BuildProgram $program): Response
    {
        return $this->owns($user, $program);
    }

    public function merge(User $user): bool
    {
        return true;
    }

    private function owns(User $user, BuildProgram $program): Response
    {
        return $user->id === $program->user_id ? Response::allow() : Response::denyAsNotFound();
    }
}
