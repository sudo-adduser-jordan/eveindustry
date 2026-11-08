<?php

namespace Tests\Unit;

use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

/**
 * A job whose $timeout reaches its connection's retry_after is handed to a
 * second worker while the first is still running it. These tests tie every
 * queued job to the Horizon supervisor that runs its queue and check the
 * margin against that supervisor's connection.
 */
class JobTimeoutTest extends TestCase
{
    private const MARGIN_SECONDS = 30;

    public function test_every_job_timeout_fits_within_its_connections_retry_after(): void
    {
        $jobs = $this->queuedJobs();

        $this->assertNotEmpty($jobs);

        foreach ($jobs as $class => $source) {
            $timeout = (new \ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

            $this->assertIsInt($timeout, "{$class} must declare an explicit \$timeout.");

            $queue = $this->queueFor($source);
            $retryAfter = $this->retryAfterForQueue($queue);

            $this->assertLessThan(
                $retryAfter - self::MARGIN_SECONDS,
                $timeout,
                "{$class} runs on '{$queue}' with \$timeout {$timeout}s, but that queue's retry_after is {$retryAfter}s."
            );
        }
    }

    public function test_every_horizon_supervisor_timeout_fits_within_its_connections_retry_after(): void
    {
        foreach (config('horizon.defaults') as $name => $supervisor) {
            $retryAfter = config("queue.connections.{$supervisor['connection']}.retry_after");

            $this->assertIsInt($retryAfter, "Supervisor {$name} uses an unknown queue connection.");
            $this->assertLessThan($retryAfter - self::MARGIN_SECONDS, $supervisor['timeout'], "Supervisor {$name} timeout is too close to retry_after.");
        }
    }

    public function test_horizon_runs_the_three_queues_within_five_processes_per_environment(): void
    {
        $queues = [];
        foreach (config('horizon.defaults') as $supervisor) {
            $queues = array_merge($queues, (array) $supervisor['queue']);
        }
        sort($queues);
        $this->assertSame(['default', 'market', 'updates'], $queues);

        foreach (config('horizon.environments') as $env => $overrides) {
            $total = 0;
            foreach (config('horizon.defaults') as $name => $supervisor) {
                $total += (int) ($overrides[$name]['maxProcesses'] ?? $supervisor['maxProcesses']);
            }

            $this->assertLessThanOrEqual(5, $total, "Horizon '{$env}' runs {$total} worker processes; the ceiling is 5.");
        }

        $production = config('horizon.environments.production');
        $this->assertSame(1, $production['supervisor-2']['maxProcesses'], 'updates runs one process');
    }

    public function test_region_order_sweep_jobs_run_on_the_market_queue(): void
    {
        foreach ([\App\Jobs\FetchRegionOrders::class, \App\Jobs\FetchRegionOrderPages::class, \App\Jobs\CompleteMarketOrderSweep::class] as $class) {
            $this->assertSame('market', $this->queueFor(file_get_contents((new \ReflectionClass($class))->getFileName())), $class);
        }

        $this->assertSame('updates', $this->queueFor(file_get_contents(app_path('Jobs/PrecomputeManufacturingCostsChunk.php'))));
    }

    /**
     * @return array<class-string, string> job class => source
     */
    private function queuedJobs(): array
    {
        $jobs = [];

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');

            if (is_subclass_of($class, ShouldQueue::class)) {
                $jobs[$class] = file_get_contents($file);
            }
        }

        return $jobs;
    }

    private function queueFor(string $source): string
    {
        return preg_match("/\\\$this->onQueue\\('([^']+)'\\)/", $source, $m) ? $m[1] : 'default';
    }

    private function retryAfterForQueue(string $queue): int
    {
        foreach (config('horizon.defaults') as $supervisor) {
            if (in_array($queue, (array) $supervisor['queue'], true)) {
                return (int) config("queue.connections.{$supervisor['connection']}.retry_after");
            }
        }

        $this->fail("No Horizon supervisor runs the '{$queue}' queue.");
    }
}
