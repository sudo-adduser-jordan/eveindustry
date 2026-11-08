<?php

namespace App\Policies;

/**
 * Wholemap signatures, connections and system notes: owner only, 403 for
 * anyone else. Registered for each model in PlanningPolicyServiceProvider.
 */
class WholemapRecordPolicy extends OwnedRecordPolicy {}
