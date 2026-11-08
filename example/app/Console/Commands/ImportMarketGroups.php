<?php

namespace App\Console\Commands;

use App\Models\MarketGroup;
use App\Services\EsiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportMarketGroups extends Command
{
    protected $signature = 'esi:import-market-groups';

    protected $description = 'Import market groups from the ESI API into the database';

    public function __construct(protected EsiService $esi)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Fetching market group IDs from ESI...');

        $groupIds = $this->esi->getMarketGroupIds();

        if ($groupIds === null || empty($groupIds)) {
            $this->error('Failed to fetch market group IDs from ESI.');

            return self::FAILURE;
        }

        $this->info('Found '.count($groupIds).' market groups. Importing...');

        $count = 0;
        $batch = [];
        $now = now();

        foreach ($groupIds as $groupId) {
            $info = $this->esi->getMarketGroupInfo((int) $groupId);

            if ($info === null) {
                $this->warn("  Skipping group {$groupId} — ESI returned null.");

                continue;
            }

            $batch[] = [
                'market_group_id' => (int) ($info['market_group_id'] ?? $groupId),
                'name' => $info['name'] ?? "Group {$groupId}",
                'description' => $info['description'] ?? '',
                'parent_group_id' => $info['parent_group_id'] ?? null,
                'icon_id' => null,
                'has_types' => ! empty($info['types']),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                MarketGroup::upsert($batch, ['market_group_id'], [
                    'name', 'description', 'parent_group_id', 'icon_id', 'has_types', 'updated_at',
                ]);
                $count += count($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            MarketGroup::upsert($batch, ['market_group_id'], [
                'name', 'description', 'parent_group_id', 'icon_id', 'has_types', 'updated_at',
            ]);
            $count += count($batch);
        }

        $this->info("Imported {$count} market groups from ESI.");
        Log::info("esi:import-market-groups completed: {$count} groups imported.");

        return self::SUCCESS;
    }
}
