<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEveTypeChunk;
use App\Services\EsiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportEveTypes extends Command
{
    protected $signature = 'esi:import-types {--published-only : Only import published types} {--sync : Process synchronously instead of dispatching queued jobs}';

    protected $description = 'Fetch all EVE Online item types from ESI and dispatch queued jobs to store them';

    public function __construct(protected EsiService $esi)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $publishedOnly = $this->option('published-only');
        $sync = $this->option('sync');
        $this->info('Fetching type IDs from ESI...');

        // Fetch the first page to get total page count
        $firstPage = $this->esi->getUniverseTypeIds(1);
        if (!$firstPage) {
            $this->error('Failed to fetch type IDs from ESI.');
            return self::FAILURE;
        }

        $totalPages = $firstPage['total_pages'];
        $this->info("Found {$totalPages} pages of type IDs to process.");

        $allTypeIds = $firstPage['type_ids'];

        // Fetch remaining pages
        $bar = $this->output->createProgressBar($totalPages);
        $bar->start();
        $bar->advance();

        for ($page = 2; $page <= $totalPages; $page++) {
            $result = $this->esi->getUniverseTypeIds($page);
            if ($result && !empty($result['type_ids'])) {
                $allTypeIds = array_merge($allTypeIds, $result['type_ids']);
            } else {
                $this->warn("Failed to fetch page {$page}, skipping.");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $totalTypes = count($allTypeIds);
        $chunkSize = 1000;
        $chunks = array_chunk($allTypeIds, $chunkSize);
        $jobCount = count($chunks);

        $this->info("Collected {$totalTypes} type IDs. Dispatching {$jobCount} jobs ({$chunkSize} types each)...");

        foreach ($chunks as $i => $chunk) {
            if ($sync) {
                ProcessEveTypeChunk::dispatchSync($chunk, $publishedOnly);
                $this->info("  Processed chunk " . ($i + 1) . "/{$jobCount}");
            } else {
                ProcessEveTypeChunk::dispatch($chunk, $publishedOnly);
            }
        }

        $mode = $sync ? 'processed synchronously' : 'dispatched to queue';
        $this->info("Done — {$jobCount} jobs {$mode}.");

        Log::info('EVE types import jobs dispatched', [
            'total_ids' => $totalTypes,
            'jobs' => $jobCount,
            'chunk_size' => $chunkSize,
            'sync' => $sync,
        ]);

        return self::SUCCESS;
    }
}
