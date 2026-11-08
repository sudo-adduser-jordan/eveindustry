<?php

namespace App\Console\Commands;

use App\Models\MarketOrderSweep;
use App\Models\MarketRegionState;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PruneSnapshotGenerations extends Command
{
    protected $signature = 'market:snapshots-prune {--write : Delete generations outside the retention window}';

    protected $description = 'Show or prune old public-contract and market aggregate generations';

    public function handle(): int
    {
        if (! config('snapshot_retention.enabled', true)) {
            $this->info('Snapshot generation retention is disabled.');

            return self::SUCCESS;
        }

        $batchSize = max(1, min(5000, (int) config('snapshot_retention.batch_size', 500)));
        $write = (bool) $this->option('write');
        $contractLock = null;
        $contractLockAcquired = false;
        if ($write) {
            $contractLock = Cache::store(config('esi.coordination_store'))
                ->lock('public-contract-snapshot-writer', (int) config('snapshot_retention.public_contracts.writer_lock_seconds', 3600));
            $contractLockAcquired = $contractLock->get();
            if (! $contractLockAcquired) {
                $this->warn('A public-contract import is active; contract snapshots were left unchanged.');
            }
        }

        try {
            $contracts = $this->publicContractCandidates();
            $this->line(($write ? 'Public-contract generations eligible for pruning: ' : 'Public-contract generations that would be pruned: ').$contracts->count());
            if ($write && $contractLockAcquired) {
                foreach ($contracts as $generation) {
                    if ($this->deleteContractGeneration((int) $generation->id, $batchSize)) {
                        $this->line("Pruned public-contract generation {$generation->id}.");
                    }
                }
            }

            $marketCount = $this->pruneMarketGenerations($batchSize, $write);
            $this->line(($write ? 'Market aggregate rows pruned: ' : 'Market aggregate rows that would be pruned: ').$marketCount);
        } finally {
            $contractLock?->release();
        }

        if (! $write) {
            $this->info('Dry run only. Pass --write to apply bounded cleanup.');
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, object> */
    private function publicContractCandidates(): Collection
    {
        $now = now();
        $graceCutoff = $now->copy()->subHours(max(3, (int) config('snapshot_retention.public_contracts.grace_hours', 3)));
        $failedCutoff = $now->copy()->subHours(max(1, (int) config('snapshot_retention.public_contracts.failed_after_hours', 24)));
        $keepCount = max(2, (int) config('snapshot_retention.public_contracts.generations', 2));
        $activeId = DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');
        $keepIds = DB::table('public_contract_generations')->where('status', 'published')
            ->orderByDesc('id')->limit($keepCount)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($activeId !== null) {
            $keepIds[] = (int) $activeId;
        }

        return DB::table('public_contract_generations')
            ->where(function ($query) use ($graceCutoff, $failedCutoff, $keepIds) {
                $query->where(function ($published) use ($graceCutoff, $keepIds) {
                    $published->where('status', 'published')->where('published_at', '<', $graceCutoff);
                    if ($keepIds !== []) {
                        $published->whereNotIn('id', array_values(array_unique($keepIds)));
                    }
                })->orWhere(function ($incomplete) use ($failedCutoff) {
                    $incomplete->whereIn('status', ['failed', 'superseded'])
                        ->where('created_at', '<', $failedCutoff);
                });
            })
            ->orderBy('id')->get(['id', 'status']);
    }

    private function deleteContractGeneration(int $generationId, int $batchSize): bool
    {
        if (! $this->contractGenerationIsPrunable($generationId)) {
            return false;
        }

        while (true) {
            $ids = DB::table('public_contract_snapshot_items')->where('generation_id', $generationId)
                ->orderBy('id')->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted = DB::transaction(function () use ($generationId, $ids): int {
                if ($this->activeContractGenerationId() !== $generationId) {
                    return DB::table('public_contract_snapshot_items')->where('generation_id', $generationId)->whereIn('id', $ids)->delete();
                }

                return 0;
            });
            if ($deleted === 0) {
                return false;
            }
        }

        while (true) {
            $contractIds = DB::table('public_contract_snapshot_contracts')->where('generation_id', $generationId)
                ->orderBy('contract_id')->limit($batchSize)->pluck('contract_id');
            if ($contractIds->isEmpty()) {
                break;
            }
            $deleted = DB::transaction(function () use ($generationId, $contractIds): int {
                if ($this->activeContractGenerationId() !== $generationId) {
                    return DB::table('public_contract_snapshot_contracts')->where('generation_id', $generationId)->whereIn('contract_id', $contractIds)->delete();
                }

                return 0;
            });
            if ($deleted === 0) {
                return false;
            }
        }

        return DB::transaction(function () use ($generationId): bool {
            $state = DB::table('public_contract_snapshot_state')->where('id', 1)->lockForUpdate()->first();
            if ((int) ($state->active_generation_id ?? 0) === $generationId || ! $this->contractGenerationIsPrunable($generationId)) {
                return false;
            }

            DB::table('public_contract_generations')->where('id', $generationId)->delete();

            return true;
        });
    }

    private function contractGenerationIsPrunable(int $generationId): bool
    {
        $activeId = $this->activeContractGenerationId();
        if ($activeId === $generationId) {
            return false;
        }

        return $this->publicContractCandidates()->contains(fn ($row) => (int) $row->id === $generationId);
    }

    private function activeContractGenerationId(): int
    {
        return (int) DB::table('public_contract_snapshot_state')->where('id', 1)->value('active_generation_id');
    }

    private function pruneMarketGenerations(int $batchSize, bool $write): int
    {
        $regionIds = DB::table('market_order_sweeps')->where('status', MarketOrderSweep::STATUS_COMPLETED)
            ->distinct()->pluck('region_id');
        $pruned = 0;
        $graceCutoff = now()->subHours(max(3, (int) config('snapshot_retention.market.grace_hours', 3)));
        $keepCount = max(2, (int) config('snapshot_retention.market.generations', 2));

        foreach ($regionIds as $regionId) {
            $regionId = (int) $regionId;
            $protected = $this->protectedMarketSweepIds($regionId, $keepCount, $graceCutoff);
            $candidates = MarketOrderSweep::where('region_id', $regionId)
                ->where('status', MarketOrderSweep::STATUS_COMPLETED)
                ->where('finished_at', '<', $graceCutoff)
                ->whereNotIn('sweep_id', $protected)
                ->orderBy('generation')->pluck('sweep_id');

            foreach ($candidates as $sweepId) {
                $removed = $write
                    ? $this->deleteMarketSweepAggregates($regionId, (string) $sweepId, $batchSize, $keepCount, $graceCutoff)
                    : (int) DB::table('market_aggregates')->where('region_id', $regionId)->where('sweep_id', $sweepId)->count();
                $pruned += $removed;
            }
        }

        return $pruned;
    }

    /** @return list<string> */
    private function protectedMarketSweepIds(int $regionId, int $keepCount, $graceCutoff): array
    {
        $ids = MarketOrderSweep::where('region_id', $regionId)
            ->where('status', MarketOrderSweep::STATUS_COMPLETED)
            ->orderByDesc('generation')->limit($keepCount)->pluck('sweep_id')->all();
        $active = MarketRegionState::where('region_id', $regionId)->value('active_sweep_id');
        if ($active !== null) {
            $ids[] = $active;
        }
        $ids = array_merge($ids, MarketOrderSweep::where('region_id', $regionId)
            ->where('status', MarketOrderSweep::STATUS_COMPLETED)
            ->where('finished_at', '>=', $graceCutoff)->pluck('sweep_id')->all());

        return array_values(array_unique($ids));
    }

    private function deleteMarketSweepAggregates(int $regionId, string $sweepId, int $batchSize, int $keepCount, $graceCutoff): int
    {
        $deleted = 0;
        while (true) {
            $ids = DB::table('market_aggregates')->where('region_id', $regionId)->where('sweep_id', $sweepId)
                ->orderBy('id')->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                return $deleted;
            }

            $chunkDeleted = DB::transaction(function () use ($regionId, $sweepId, $ids, $keepCount, $graceCutoff): int {
                $state = MarketRegionState::where('region_id', $regionId)->lockForUpdate()->first();
                $running = MarketOrderSweep::where('region_id', $regionId)
                    ->whereIn('status', [MarketOrderSweep::STATUS_RUNNING, MarketOrderSweep::STATUS_COMPLETING])->exists();
                if ($running || in_array($sweepId, $this->protectedMarketSweepIds($regionId, $keepCount, $graceCutoff), true)) {
                    return 0;
                }

                // The durable active pointer is checked while its row is locked.
                if ($state?->active_sweep_id === $sweepId) {
                    return 0;
                }

                return DB::table('market_aggregates')->where('region_id', $regionId)
                    ->where('sweep_id', $sweepId)->whereIn('id', $ids)->delete();
            });

            if ($chunkDeleted === 0) {
                return $deleted;
            }
            $deleted += $chunkDeleted;
        }
    }
}
