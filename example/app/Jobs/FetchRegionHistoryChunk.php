<?php

namespace App\Jobs;

use App\Models\MarketHistory;
use App\Services\Esi\Diagnostics\EsiDiagnosticsRecorder;
use App\Services\EsiService;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchRegionHistoryChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Reduced timeout: all requests in the chunk are fired concurrently, so
     * the wall-clock time is roughly the slowest single ESI response (≤30 s)
     * plus processing overhead — 120 s is more than enough.
     */
    public int $timeout = 120;

    /**
     * Seconds to wait before retrying after a rate-limit (420/502/503).
     */
    public int $backoff = 10;

    /**
     * @param  int  $regionId  The region to fetch history for
     * @param  array  $typeIds  Array of type IDs to process in this chunk
     */
    public function __construct(
        protected int $regionId,
        protected array $typeIds,
    ) {
        $this->onQueue('updates');
    }

    public function handle(EsiService $esi): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($esi->isEsiErrorLimited()) {
            Log::warning("FetchRegionHistoryChunk: ESI error budget exhausted, releasing region {$this->regionId} back to queue");
            $this->release($esi->esiErrorResetSeconds() ?: $this->backoff);

            return;
        }

        $baseUrl = 'https://esi.evetech.net/latest';

        // Load cached ETags so we can send conditional GET requests.
        // ESI returns 304 Not Modified when data is unchanged, saving bandwidth
        // and skipping unnecessary DB upserts.
        $etags = [];
        foreach ($this->typeIds as $typeId) {
            $etag = Cache::get("market.history.etag.{$this->regionId}.{$typeId}");
            if ($etag !== null) {
                $etags[$typeId] = $etag;
            }
        }

        // Fire all requests for this chunk concurrently instead of sequentially.
        // Wall-clock time drops from (N × latency) to roughly one request latency.
        $responses = Http::pool(function (Pool $pool) use ($baseUrl, $etags) {
            foreach ($this->typeIds as $typeId) {
                $request = $pool->as((string) $typeId)->connectTimeout(5)->timeout(30);

                if (isset($etags[$typeId])) {
                    $request = $request->withHeaders(['If-None-Match' => $etags[$typeId]]);
                }

                $request->get("{$baseUrl}/markets/{$this->regionId}/history/", [
                    'type_id' => $typeId,
                ]);
            }
        });

        $diagnostics = app(EsiDiagnosticsRecorder::class);
        $historyEndpoint = "/markets/{$this->regionId}/history/";
        $processed = 0;
        $skipped = 0;
        $errors = 0;
        $now = now();
        $cutoffDate = Carbon::now()->subDays(30)->toDateString(); // 'YYYY-MM-DD' string comparison is valid for ISO dates
        $rateLimited = false;

        foreach ($this->typeIds as $typeId) {
            $response = $responses[(string) $typeId] ?? null;

            // Connection failure or timeout
            if ($response === null || $response instanceof ConnectionException || $response instanceof \Throwable) {
                $msg = ($response instanceof \Throwable) ? $response->getMessage() : 'missing response';
                Log::error("FetchRegionHistoryChunk: request failed for region {$this->regionId} type {$typeId}: {$msg}");
                $errors++;
                // This job bypasses EsiHttpClient, so it reports its own wire attempts to diagnostics.
                $diagnostics->connectionFailure('get', $historyEndpoint, null, null);

                continue;
            }

            $diagnostics->httpAttempt('get', $historyEndpoint, null, $response, null, 'legacy');

            $esi->trackEsiErrorLimit($response);

            // Rate limited — collect the flag and finish iterating so we still
            // track error limits for all responses before releasing.
            if ($response->status() === 420 || $response->status() === 503) {
                Log::warning("FetchRegionHistoryChunk: rate limited (HTTP {$response->status()}) on region {$this->regionId} type {$typeId}");
                $rateLimited = true;

                continue;
            }

            // 304 Not Modified — data unchanged since last fetch, nothing to upsert
            if ($response->status() === 304) {
                $processed++;
                $skipped++;

                continue;
            }

            if (! $response->successful()) {
                Log::warning("FetchRegionHistoryChunk: HTTP {$response->status()} for region {$this->regionId} type {$typeId}");
                $errors++;

                continue;
            }

            // Persist the ETag so the next run can skip unchanged data
            $etag = $response->header('ETag');
            if ($etag) {
                Cache::put("market.history.etag.{$this->regionId}.{$typeId}", $etag, now()->addHours(25));
            }

            $entries = $response->json() ?? [];

            if (empty($entries)) {
                $processed++;

                continue;
            }

            $rows = [];

            foreach ($entries as $entry) {
                if (($entry['date'] ?? '') < $cutoffDate) {
                    continue;
                }
                $rows[] = [
                    'region_id' => $this->regionId,
                    'type_id' => $typeId,
                    'date' => $entry['date'],
                    'average' => (float) ($entry['average'] ?? 0),
                    'highest' => (float) ($entry['highest'] ?? 0),
                    'lowest' => (float) ($entry['lowest'] ?? 0),
                    'volume' => (int) ($entry['volume'] ?? 0),
                    'order_count' => (int) ($entry['order_count'] ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Upsert in sub-chunks to stay within DB parameter limits
            foreach (array_chunk($rows, 500) as $chunk) {
                MarketHistory::upsert($chunk, ['region_id', 'type_id', 'date'], [
                    'average', 'highest', 'lowest', 'volume', 'order_count', 'updated_at',
                ]);
            }

            $processed++;
        }

        if ($rateLimited) {
            $this->release($this->backoff);

            return;
        }

        Log::info("FetchRegionHistoryChunk: region {$this->regionId} processed {$processed} types ({$skipped} unchanged via ETag), {$errors} errors");
    }
}
