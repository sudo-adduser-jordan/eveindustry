<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Readiness checks for GET /api/v1/health and the post-deploy smoke test:
 * the database answers and the cache store can write and read back.
 */
class HealthService
{
    public const OK = 'ok';

    public const FAILED = 'failed';

    /**
     * @return array{status: string, checks: array{database: string, cache: string}}
     */
    public function check(): array
    {
        $checks = [
            'database' => $this->run('database', fn () => DB::select('select 1 as ok') !== []),
            'cache' => $this->run('cache', function () {
                $key = 'health.'.Str::random(12);
                $value = Str::random(8);
                Cache::put($key, $value, 10);
                $read = Cache::get($key);
                Cache::forget($key);

                return $read === $value;
            }),
        ];

        return [
            'status' => in_array(self::FAILED, $checks, true) ? self::FAILED : self::OK,
            'checks' => $checks,
        ];
    }

    /**
     * @param  callable(): bool  $probe
     */
    private function run(string $name, callable $probe): string
    {
        try {
            return $probe() ? self::OK : self::FAILED;
        } catch (\Throwable $e) {
            Log::error("Health check {$name} failed: {$e->getMessage()}");

            return self::FAILED;
        }
    }
}
