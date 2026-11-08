<?php

namespace App\Jobs;

use App\Models\MarketHistory;
use App\Services\MarketTradingService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportDailyMarketHistory implements ShouldQueue
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

    /** Number of CSV rows to upsert per database batch. */
    public const UPSERT_CHUNK_SIZE = 500;

    /**
     * @param  Carbon  $date  The date to import market history for
     */
    public function __construct(
        protected Carbon $date,
    ) {
        $this->onQueue('updates');
    }

    public function getDate(): Carbon
    {
        return $this->date;
    }

    public function handle(): void
    {
        $dateStr = $this->date->format('Y-m-d');
        $year    = $this->date->format('Y');
        $url     = "https://data.everef.net/market-history/{$year}/market-history-{$dateStr}.csv.bz2";

        Log::info("ImportDailyMarketHistory: downloading {$url}");

        $response = Http::timeout(120)->get($url);

        if (! $response->successful()) {
            Log::warning("ImportDailyMarketHistory: HTTP {$response->status()} fetching {$url}");
            return;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'eve_mh_');

        try {
            file_put_contents($tmpFile, $response->body());
            $this->processFile($tmpFile, $dateStr);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    private function processFile(string $tmpFile, string $dateStr): void
    {
        $pipe = popen('bzip2 -dc ' . escapeshellarg($tmpFile), 'r');

        if ($pipe === false) {
            Log::error("ImportDailyMarketHistory: failed to decompress bz2 file for date {$dateStr}");
            return;
        }

        try {
            $header  = null;
            $batch   = [];
            $total   = 0;
            $now     = now();

            $knownRegionIds = array_keys(MarketTradingService::REGIONS);

            while (($line = fgets($pipe)) !== false) {
                if ($line === '') {
                    continue;
                }

                $row = str_getcsv(rtrim($line, "\r\n"));

                if ($header === null) {
                    $header = $row;
                    continue;
                }

                if (count($row) !== count($header)) {
                    continue;
                }

                $data = array_combine($header, $row);

                $regionId = (int) ($data['region_id'] ?? $data['regionID'] ?? 0);

                if (! in_array($regionId, $knownRegionIds, true)) {
                    continue;
                }

                $batch[] = [
                    'region_id'   => $regionId,
                    'type_id'     => (int) ($data['type_id'] ?? $data['typeID'] ?? 0),
                    'date'        => $data['date'],
                    'average'     => (float) ($data['average'] ?? 0),
                    'highest'     => (float) ($data['highest'] ?? 0),
                    'lowest'      => (float) ($data['lowest'] ?? 0),
                    'volume'      => (int) ($data['volume'] ?? 0),
                    'order_count' => (int) ($data['order_count'] ?? $data['orderCount'] ?? 0),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];

                if (count($batch) >= self::UPSERT_CHUNK_SIZE) {
                    MarketHistory::upsert($batch, ['region_id', 'type_id', 'date'], [
                        'average', 'highest', 'lowest', 'volume', 'order_count', 'updated_at',
                    ]);
                    $total += count($batch);
                    $batch = [];
                }
            }

            if (! empty($batch)) {
                MarketHistory::upsert($batch, ['region_id', 'type_id', 'date'], [
                    'average', 'highest', 'lowest', 'volume', 'order_count', 'updated_at',
                ]);
                $total += count($batch);
            }

            Log::info("ImportDailyMarketHistory: upserted {$total} rows for date {$dateStr}");

        } finally {
            pclose($pipe);
        }
    }
}
