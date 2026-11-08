<?php

namespace App\Services\Programs;

use App\Models\StationConfig;
use App\Models\User;

/**
 * Creates, changes and deletes a user's structures (station configs).
 *
 * Shared by the old /api/structures write routes and the /api/v1/structures
 * ones. At most one structure per activity type is the user's default.
 */
class StructureConfigWriter
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, array $validated): StationConfig
    {
        if (! empty($validated['is_default'])) {
            $user->stationConfigs()
                ->where('activity_type', $validated['activity_type'])
                ->update(['is_default' => false]);
        }

        return $user->stationConfigs()->create($validated);
    }

    /**
     * Update the sent fields. Making it the default un-defaults the user's
     * other structures of the same activity type.
     *
     * @param  array<string, mixed>  $validated
     */
    public function update(User $user, StationConfig $structure, array $validated): StationConfig
    {
        if (! empty($validated['is_default'])) {
            $activityType = $validated['activity_type'] ?? $structure->activity_type;
            $user->stationConfigs()
                ->where('activity_type', $activityType)
                ->where('id', '!=', $structure->id)
                ->update(['is_default' => false]);
        }

        $structure->update($validated);

        return $structure->fresh();
    }

    public function delete(StationConfig $structure): void
    {
        $structure->delete();
    }

    public function setDefault(User $user, StationConfig $structure): StationConfig
    {
        $user->stationConfigs()
            ->where('activity_type', $structure->activity_type)
            ->update(['is_default' => false]);
        $structure->update(['is_default' => true]);

        return $structure->fresh();
    }
}
