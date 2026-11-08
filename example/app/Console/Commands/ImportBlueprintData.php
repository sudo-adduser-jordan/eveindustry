<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBlueprintDataChunk;
use App\Models\EveType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportBlueprintData extends Command
{
    protected $signature = 'sde:import-blueprints {--sync : Process synchronously instead of dispatching queued jobs}';

    protected $description = 'Fetch blueprint/reaction SDE data from Fuzzwork and dispatch queued jobs to store them';

    public function handle(): int
    {
        $sync = $this->option('sync');
        $this->info('Collecting blueprint type IDs from local eve_types table...');

        // Get all blueprint type IDs from the local DB
        $blueprintTypeIds = EveType::where('published', true)
            ->where('name', 'like', '%Blueprint%')
            ->pluck('type_id')
            ->toArray();

        // Also grab reaction formula type IDs
        $reactionTypeIds = EveType::where('published', true)
            ->where('name', 'like', '%Reaction Formula%')
            ->pluck('type_id')
            ->toArray();

        $allTypeIds = array_values(array_unique(array_merge($blueprintTypeIds, $reactionTypeIds)));

        if (empty($allTypeIds)) {
            $this->warn('No blueprint/reaction types found in eve_types table. Run esi:import-types first.');
            return self::FAILURE;
        }

        $total = count($allTypeIds);
        $chunkSize = 100;
        $chunks = array_chunk($allTypeIds, $chunkSize);
        $jobCount = count($chunks);

        $this->info("Found {$total} blueprint/reaction types. Dispatching {$jobCount} jobs ({$chunkSize} types each)...");

        foreach ($chunks as $i => $chunk) {
            if ($sync) {
                ProcessBlueprintDataChunk::dispatchSync($chunk);
                $this->info("  Processed chunk " . ($i + 1) . "/{$jobCount}");
            } else {
                ProcessBlueprintDataChunk::dispatch($chunk);
            }
        }

        $mode = $sync ? 'processed synchronously' : 'dispatched to queue';
        $this->info("Done — {$jobCount} jobs {$mode}.");

        Log::info('Blueprint SDE import jobs dispatched', [
            'total' => $total,
            'jobs' => $jobCount,
            'chunk_size' => $chunkSize,
            'sync' => $sync,
        ]);

        return self::SUCCESS;
    }
}
