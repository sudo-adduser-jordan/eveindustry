<?php

namespace App\Services\Planning;

use App\Models\StationConfig;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Manufacturing station configurations. Shared by the legacy calculator
 * routes and /api/v1. Callers check ownership and validate input.
 */
class StationConfigService
{
    /**
     * @return Collection<int, StationConfig>
     */
    public function list(User $user): Collection
    {
        return $this->query($user)->get();
    }

    public function paginate(User $user, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->query($user)->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): StationConfig
    {
        $data['activity_type'] = 'manufacturing';

        if (! empty($data['is_default'])) {
            $user->stationConfigs()
                ->where('activity_type', 'manufacturing')
                ->update(['is_default' => false]);
        }

        return $user->stationConfigs()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, StationConfig $config, array $data): StationConfig
    {
        if (! empty($data['is_default'])) {
            $user->stationConfigs()
                ->where('activity_type', $config->activity_type)
                ->where('id', '!=', $config->id)
                ->update(['is_default' => false]);
        }

        $config->update($data);

        return $config->fresh();
    }

    public function delete(StationConfig $config): void
    {
        $config->delete();
    }

    public function setDefault(User $user, StationConfig $config): StationConfig
    {
        $user->stationConfigs()
            ->where('activity_type', $config->activity_type)
            ->update(['is_default' => false]);
        $config->update(['is_default' => true]);

        return $config->fresh();
    }

    /**
     * @return HasMany<StationConfig>
     */
    private function query(User $user): HasMany
    {
        return $user->stationConfigs()
            ->where('activity_type', 'manufacturing')
            ->orderByDesc('is_default')
            ->orderBy('name');
    }
}
