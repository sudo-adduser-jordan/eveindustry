<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ImportPublicContracts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    /** Number of rows written in one bounded database statement. */
    public const UPSERT_CHUNK_SIZE = 500;

    public const DATA_URL = 'https://data.everef.net/public-contracts/public-contracts-latest.v2.tar.bz2';

    public function __construct()
    {
        // contracts:import runs this inline today; if it is ever queued it
        // needs the long queue, whose retry_after outlasts the 900 s timeout.
        $this->onQueue('updates');
    }

    public function handle(): void
    {
        $lock = Cache::store(config('esi.coordination_store'))->lock('public-contract-snapshot-writer', 1200);
        if (! $lock->get()) {
            Log::info('Public-contract collection already running; duplicate import skipped.');

            return;
        }
        try {
            $this->collectArchive();
        } finally {
            $lock->release();
        }
    }

    private function collectArchive(): void
    {
        Log::info('ImportPublicContracts: downloading '.self::DATA_URL);
        $tmpFile = tempnam(sys_get_temp_dir(), 'eve_pc_');

        try {
            $response = Http::timeout(300)->withOptions(['sink' => $tmpFile])->get(self::DATA_URL);
            if (! $response->successful()) {
                throw new RuntimeException("Everef download failed with HTTP {$response->status()}.");
            }
            if ((int) filesize($tmpFile) === 0) {
                throw new RuntimeException('Everef download produced an empty archive.');
            }

            $this->processArchive($tmpFile);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /** Extract and stage outside the publication transaction; only pointer flip is atomic. */
    private function processArchive(string $archivePath): void
    {
        $extractDir = sys_get_temp_dir().'/eve_pc_extract_'.bin2hex(random_bytes(8));
        if (! mkdir($extractDir, 0755, true) && ! is_dir($extractDir)) {
            throw new RuntimeException('Unable to create the Everef extraction directory.');
        }

        $generationId = null;
        try {
            $output = [];
            exec('tar -xjf '.escapeshellarg($archivePath).' -C '.escapeshellarg($extractDir).' 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                throw new RuntimeException('Unable to extract the Everef public-contract archive.');
            }

            $contractsCsv = $this->findFile($extractDir, 'contracts.csv');
            $itemsCsv = $this->findFile($extractDir, 'contract_items.csv');
            if ($contractsCsv === null || $itemsCsv === null) {
                throw new RuntimeException('Everef archive is missing contracts.csv or contract_items.csv.');
            }

            $generationId = DB::table('public_contract_generations')->insertGetId([
                'status' => 'building',
                'contract_count' => 0,
                'item_count' => 0,
                'created_at' => now(),
            ]);

            $contractCount = $this->importContractsCsv($contractsCsv, $generationId);
            $itemCount = $this->importContractItemsCsv($itemsCsv, $generationId);
            $orphanCount = DB::table('public_contract_snapshot_items as i')
                ->leftJoin('public_contract_snapshot_contracts as c', function ($join) {
                    $join->on('c.generation_id', '=', 'i.generation_id')
                        ->on('c.contract_id', '=', 'i.contract_id');
                })
                ->where('i.generation_id', $generationId)
                ->whereNull('c.contract_id')
                ->count();
            if ($orphanCount > 0) {
                throw new RuntimeException("Everef snapshot contains {$orphanCount} items without a contract.");
            }

            DB::transaction(function () use ($generationId, $contractCount, $itemCount): void {
                $now = now();
                DB::table('public_contract_generations')->where('id', $generationId)->update([
                    'status' => 'complete',
                    'contract_count' => $contractCount,
                    'item_count' => $itemCount,
                ]);
                $state = DB::table('public_contract_snapshot_state')->where('id', 1)->lockForUpdate()->first();
                if ($state !== null && (int) $state->active_generation_id >= $generationId) {
                    DB::table('public_contract_generations')->where('id', $generationId)->update(['status' => 'superseded']);

                    return;
                }
                DB::table('public_contract_generations')->where('id', $generationId)->update([
                    'status' => 'published',
                    'published_at' => $now,
                ]);
                DB::table('public_contract_snapshot_state')->updateOrInsert(['id' => 1], [
                    'active_generation_id' => $generationId,
                    'updated_at' => $now,
                ]);
            });

            Log::info("ImportPublicContracts: published generation {$generationId} with {$contractCount} contracts and {$itemCount} items");
        } catch (Throwable $exception) {
            if ($generationId !== null) {
                DB::table('public_contract_generations')->where('id', $generationId)->update(['status' => 'failed']);
            }
            Log::warning('ImportPublicContracts: snapshot was not published', [
                'generation_id' => $generationId,
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
                'message' => substr($exception->getMessage(), 0, 500),
            ]);
            throw $exception;
        } finally {
            $this->removeDirectory($extractDir);
        }
    }

    private function findFile(string $directory, string $filename): ?string
    {
        $direct = $directory.'/'.$filename;
        if (file_exists($direct)) {
            return $direct;
        }
        foreach (glob($directory.'/*', GLOB_ONLYDIR) as $subdir) {
            $path = $subdir.'/'.$filename;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function importContractsCsv(string $csvPath, int $generationId): int
    {
        $required = ['contract_id', 'region_id', 'type', 'price'];

        return $this->importCsv($csvPath, $required, function (array $data) use ($generationId): array {
            $contractId = (int) ($data['contract_id'] ?? 0);
            if ($contractId <= 0) {
                throw new RuntimeException('Everef contracts.csv contains an invalid contract_id.');
            }
            $now = now();

            return [
                'generation_id' => $generationId,
                'contract_id' => $contractId,
                'region_id' => (int) ($data['region_id'] ?? 0),
                'type' => $data['type'] ?? 'unknown',
                'title' => ($data['title'] ?? '') !== '' ? $data['title'] : null,
                'for_corporation' => filter_var($data['for_corporation'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'issuer_corporation_id' => $this->nullableInt($data['issuer_corporation_id'] ?? ''),
                'issuer_id' => $this->nullableInt($data['issuer_id'] ?? ''),
                'date_issued' => $this->nullableDate($data['date_issued'] ?? ''),
                'date_expired' => $this->nullableDate($data['date_expired'] ?? ''),
                'days_to_complete' => (int) ($data['days_to_complete'] ?? 0),
                'start_location_id' => $this->nullableInt($data['start_location_id'] ?? ''),
                'end_location_id' => $this->nullableInt($data['end_location_id'] ?? ''),
                'station_id' => $this->nullableInt($data['station_id'] ?? ''),
                'system_id' => $this->nullableInt($data['system_id'] ?? ''),
                'constellation_id' => $this->nullableInt($data['constellation_id'] ?? ''),
                'price' => (float) ($data['price'] ?? 0),
                'buyout' => $this->nullableFloat($data['buyout'] ?? ''),
                'reward' => $this->nullableFloat($data['reward'] ?? ''),
                'collateral' => $this->nullableFloat($data['collateral'] ?? ''),
                'volume' => (float) ($data['volume'] ?? 0),
                'http_last_modified' => $this->nullableDate($data['http_last_modified'] ?? ''),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, 'public_contract_snapshot_contracts');
    }

    private function importContractItemsCsv(string $csvPath, int $generationId): int
    {
        $required = ['contract_id', 'type_id', 'quantity', 'is_included'];

        return $this->importCsv($csvPath, $required, function (array $data) use ($generationId): array {
            $contractId = (int) ($data['contract_id'] ?? 0);
            $typeId = (int) ($data['type_id'] ?? 0);
            if ($contractId <= 0 || $typeId <= 0) {
                throw new RuntimeException('Everef contract_items.csv contains an invalid contract_id or type_id.');
            }
            $now = now();

            return [
                'generation_id' => $generationId,
                'contract_id' => $contractId,
                'type_id' => $typeId,
                'quantity' => (int) ($data['quantity'] ?? 1),
                'is_included' => filter_var($data['is_included'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'is_blueprint_copy' => ($data['is_blueprint_copy'] ?? '') !== '' ? filter_var($data['is_blueprint_copy'], FILTER_VALIDATE_BOOLEAN) : null,
                'material_efficiency' => $this->nullableInt($data['material_efficiency'] ?? ''),
                'time_efficiency' => $this->nullableInt($data['time_efficiency'] ?? ''),
                'runs' => $this->nullableInt($data['runs'] ?? ''),
                'record_id' => $this->nullableInt($data['record_id'] ?? ''),
                'item_id' => $this->nullableInt($data['item_id'] ?? ''),
                'http_last_modified' => $this->nullableDate($data['http_last_modified'] ?? ''),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, 'public_contract_snapshot_items');
    }

    /** Parse and write one bounded batch at a time; header-only CSV is valid empty data. */
    private function importCsv(string $path, array $required, callable $map, string $table): int
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Unable to open {$table} CSV.");
        }
        try {
            $header = fgetcsv($handle);
            if ($header === false || array_diff($required, $header) !== []) {
                throw new RuntimeException("Everef CSV for {$table} is empty or missing required columns.");
            }
            $batch = [];
            $count = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== count($header)) {
                    throw new RuntimeException("Everef CSV for {$table} contains a malformed row.");
                }
                $data = array_combine($header, $row);
                $batch[] = $map($data);
                if (count($batch) >= self::UPSERT_CHUNK_SIZE) {
                    DB::table($table)->insert($batch);
                    $count += count($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                DB::table($table)->insert($batch);
                $count += count($batch);
            }

            return $count;
        } finally {
            fclose($handle);
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === '' || $value === null ? null : (int) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === '' || $value === null ? null : (float) $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        return $value === '' || $value === null ? null : Carbon::parse($value, 'UTC')->toDateTimeString();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}
