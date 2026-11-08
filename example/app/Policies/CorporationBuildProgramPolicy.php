<?php

namespace App\Policies;

use App\Models\CorporationBuildProgram;
use App\Models\User;
use App\Services\CorporationBuildProgramService;
use Illuminate\Auth\Access\Response;

/**
 * Corporate build programs.
 *
 * Abilities: view, create, update, delete, merge.
 *
 * - view: the active config found through the user characters is for the
 *   program corporation and the user has a character there (404 if not).
 * - create/merge: the user's corporation has the feature enabled and the user
 *   is a Director/CEO or Factory Manager there (403, as the old routes).
 * - update/delete: the same, for this program's corporation. The old routes
 *   answered 404 for every failure, so this does too.
 */
class CorporationBuildProgramPolicy
{
    public function __construct(private CorporationBuildProgramService $service) {}

    public function view(User $user, CorporationBuildProgram $program): Response
    {
        // The user's active config must be for this program's corporation
        // (other corporations' programs are not revealed), and they need a
        // character in it.
        $config = $this->service->getConfigForUser($user->id);
        if (! $config || $config->corporation_id !== $program->corporation_id) {
            return Response::denyAsNotFound();
        }

        return $this->service->canUserView($user, $program->corporation_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): Response
    {
        return $this->mayManage($user, 'Insufficient role. Director, CEO, or Factory Manager required.');
    }

    public function merge(User $user): Response
    {
        return $this->mayManage($user, 'Insufficient role');
    }

    public function update(User $user, CorporationBuildProgram $program): Response
    {
        return $this->manages($user, $program);
    }

    public function delete(User $user, CorporationBuildProgram $program): Response
    {
        return $this->manages($user, $program);
    }

    private function mayManage(User $user, string $roleMessage): Response
    {
        $config = $this->service->getConfigForUser($user->id);
        if (! $config) {
            return Response::deny('Corporate build programs not enabled');
        }

        return $this->service->canUserManage($user, $config->corporation_id)
            ? Response::allow()
            : Response::deny($roleMessage);
    }

    private function manages(User $user, CorporationBuildProgram $program): Response
    {
        return $this->service->canUserManageProgram($user, $program)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
