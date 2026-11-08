<?php

namespace App\Jobs;

use App\Models\EveType;
use App\Services\EsiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEveTypeChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600;

    /**
     * @param  array<int>  $typeIds       Chunk of type IDs to process
     * @param  bool        $publishedOnly Whether to skip unpublished types
     */
    public function __construct(
        protected array $typeIds,
        protected bool $publishedOnly = false,
    ) {
        $this->onQueue('updates');
    }

    public function handle(EsiService $esi): void
    {
        $imported = 0;
        $skipped = 0;
        $failed = 0;

        foreach (array_chunk($this->typeIds, 50) as $subChunk) {
            $batch = [];

            foreach ($subChunk as $typeId) {
                $typeInfo = $esi->getTypeInfo($typeId);

                if (!$typeInfo) {
                    $failed++;
                    continue;
                }

                if ($this->publishedOnly && empty($typeInfo['published'])) {
                    $skipped++;
                    continue;
                }

                $batch[] = [
                    'type_id' => $typeId,
                    'name' => $typeInfo['name'] ?? 'Unknown',
                    'description' => $typeInfo['description'] ?? null,
                    'group_id' => $typeInfo['group_id'] ?? null,
                    'market_group_id' => $typeInfo['market_group_id'] ?? null,
                    'volume' => $typeInfo['volume'] ?? null,
                    'packaged_volume' => $typeInfo['packaged_volume'] ?? null,
                    'mass' => $typeInfo['mass'] ?? null,
                    'capacity' => $typeInfo['capacity'] ?? null,
                    'portion_size' => $typeInfo['portion_size'] ?? null,
                    'icon_id' => $typeInfo['icon_id'] ?? null,
                    'graphic_id' => $typeInfo['graphic_id'] ?? null,
                    'published' => $typeInfo['published'] ?? false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $imported++;
            }

            if (!empty($batch)) {
                EveType::upsert($batch, ['type_id'], [
                    'name', 'description', 'group_id', 'market_group_id',
                    'volume', 'packaged_volume', 'mass', 'capacity',
                    'portion_size', 'icon_id', 'graphic_id', 'published', 'updated_at',
                ]);
            }
        }

        Log::info('ProcessEveTypeChunk completed', [
            'chunk_size' => count($this->typeIds),
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }
}
