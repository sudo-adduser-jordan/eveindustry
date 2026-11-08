<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy for records that belong to exactly one user (`user_id`).
 *
 * Nothing here is shared by corporation or alliance: only the owner can see
 * or change a record. Other users' records are denied with 403, or hidden as
 * 404 when $hideOthers is set (the old routes answered 404 for plans and
 * station configs, 403 for wholemap records).
 *
 * Abilities: view, create, update, delete.
 */
abstract class OwnedRecordPolicy
{
    /** Answer 404 instead of 403 for another user's record. */
    protected bool $hideOthers = false;

    public function view(User $user, Model $record): Response
    {
        return $this->owner($user, $record);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Model $record): Response
    {
        return $this->owner($user, $record);
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->owner($user, $record);
    }

    private function owner(User $user, Model $record): Response
    {
        if ((int) $record->getAttribute('user_id') === (int) $user->id) {
            return Response::allow();
        }

        return $this->hideOthers ? Response::denyAsNotFound() : Response::deny();
    }
}
