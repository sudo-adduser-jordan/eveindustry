<?php

namespace App\Jobs;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Writes bounded manufacturing-cost chunks in a stable key order.
 */
class ManufacturingCostWriter
{
    private const MAX_ATTEMPTS = 3;

    /** @var array<int, int> Milliseconds before retry number 2 and 3. */
    private const RETRY_BACKOFF_MS = [100, 250];

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function upsert(int $regionId, array $rows): void
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                DB::transaction(function () use ($rows): void {
                    DB::table('manufacturing_costs')->upsert(
                        $rows,
                        ['product_type_id', 'region_id', 'me_level'],
                        [
                            'blueprint_type_id', 'product_name', 'blueprint_name', 'portion_size',
                            'input_cost', 'output_value', 'base_profit', 'profit_margin',
                            'sell_score', 'sell_score_avg_daily_volume', 'sell_score_avg_order_count',
                            'sell_score_active_days', 'sell_score_total_days', 'prices_as_of', 'updated_at',
                        ]
                    );
                }, 1);

                return;
            } catch (QueryException $exception) {
                $deadlock = self::isDeadlock($exception);
                $context = self::exceptionContext($regionId, count($rows), $exception);

                if ($deadlock && $attempt < self::MAX_ATTEMPTS) {
                    $backoff = self::RETRY_BACKOFF_MS[$attempt - 1];
                    Log::warning('Manufacturing cost chunk deadlocked; retrying.', $context + [
                        'attempt' => $attempt,
                        'max_attempts' => self::MAX_ATTEMPTS,
                        'backoff_ms' => $backoff,
                    ]);
                    usleep($backoff * 1000);

                    continue;
                }

                Log::error('Manufacturing cost chunk upsert failed.', $context + [
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_ATTEMPTS,
                ]);

                // Do not attach the QueryException as a previous exception:
                // its message contains full SQL and all row bindings, which
                // Laravel would otherwise persist in failed_jobs.
                throw new RuntimeException(self::safeFailureMessage($regionId, count($rows), $exception));
            }
        }
    }

    public static function isDeadlock(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1213
            || ($exception->errorInfo[0] ?? null) === '40001';
    }

    public static function safeFailureMessage(int $regionId, int $rowCount, QueryException $exception): string
    {
        $sqlState = self::sqlState($exception);
        $driverCode = self::driverCode($exception);

        return "Manufacturing cost upsert failed for region {$regionId} ({$rowCount} rows); "
            .'database exception '.get_class($exception).", SQLSTATE {$sqlState}, driver code {$driverCode}.";
    }

    /** @return array<string, int|string> */
    private static function exceptionContext(int $regionId, int $rowCount, QueryException $exception): array
    {
        return [
            'region_id' => $regionId,
            'row_count' => $rowCount,
            'exception_class' => get_class($exception),
            'sql_state' => self::sqlState($exception),
            'driver_code' => self::driverCode($exception),
        ];
    }

    private static function sqlState(QueryException $exception): string
    {
        return (string) ($exception->errorInfo[0] ?? 'unknown');
    }

    private static function driverCode(QueryException $exception): string
    {
        return (string) ($exception->errorInfo[1] ?? $exception->getCode() ?: 'unknown');
    }
}
