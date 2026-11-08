<?php

namespace App\Services\Heavy;

use App\Models\StationConfig;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * A user's own station/structure configs, for GET /api/v1/structures.
 */
class StructureListService
{
    /**
     * Defaults first, then by name.
     *
     * @param  array{activity_type?: mixed}  $filters  the filter applies whenever the key is present, even with a null value (the old route did the same)
     * @return Collection<int, StationConfig>
     */
    public function forUser(User $user, array $filters = []): Collection
    {
        $query = $user->stationConfigs();

        if (array_key_exists('activity_type', $filters)) {
            $query->where('activity_type', $filters['activity_type']);
        }

        return $query->orderByDesc('is_default')->orderBy('name')->get();
    }
}
