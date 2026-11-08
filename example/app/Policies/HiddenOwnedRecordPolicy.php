<?php

namespace App\Policies;

/**
 * Saved plans: owner only, and another user's record is
 * a 404 (it must not reveal that the record exists). Registered for each
 * model in PlanningPolicyServiceProvider.
 */
class HiddenOwnedRecordPolicy extends OwnedRecordPolicy
{
    protected bool $hideOthers = true;
}
