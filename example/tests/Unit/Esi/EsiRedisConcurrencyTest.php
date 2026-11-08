<?php

namespace Tests\Unit\Esi;

use App\Models\Character;
use App\Services\Esi\EsiAuthClient;
use App\Services\Esi\EsiRequestException;
use App\Services\EsiRateLimiter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Opt-in: disposable Redis only; no live ESI requests are made. */
class EsiRedisConcurrencyTest extends TestCase
{
    private ?string $databaseFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ESI_REDIS_INTEGRATION') !== '1' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires ESI_REDIS_INTEGRATION=1, disposable Redis and pcntl.');
        }
        config(['esi.coordination_store' => 'esi_coordination', 'cache.prefix' => 'esi_test_'.bin2hex(random_bytes(8)).'_', 'esi.application_id' => bin2hex(random_bytes(8))]);
        Cache::purge('esi_coordination');
    }

    protected function tearDown(): void
    {
        DB::purge('esi_refresh_test');
        if ($this->databaseFile !== null) {
            @unlink($this->databaseFile);
        }
        parent::tearDown();
    }

    private function concurrent(int $workers, callable $callback): array
    {
        $store = app(EsiRateLimiter::class)->store();
        $store->put('barrier', false, 30);
        $children = [];
        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                // Forked clients must not share the parent's sockets/PDO handle.
                app('redis')->purge('default');
                Cache::purge('esi_coordination');
                DB::purge('esi_refresh_test');
                $childStore = app(EsiRateLimiter::class)->store();
                $deadline = microtime(true) + 10;
                while (! $childStore->get('barrier') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                try {
                    $result = $callback($worker);
                } catch (\Throwable $e) {
                    $result = 'failed:'.get_class($e).':'.$e->getMessage();
                }
                $childStore->put('result.'.$worker, $result, 60);
                exit(0);
            }
            $children[] = $pid;
        }
        $store->put('barrier', true, 30);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        return array_map(fn ($worker) => $store->get('result.'.$worker), range(0, $workers - 1));
    }

    public function test_concurrent_reservations_never_overspend_shared_token_budget(): void
    {
        // Warm framework classes before the simultaneous workers contend.
        app(EsiRateLimiter::class)->reserve('char-notification', 'warmup');
        $results = $this->concurrent(12, function () {
            try {
                return app(EsiRateLimiter::class)->reserve('char-notification', '123') === null ? 'denied' : 'reserved';
            } catch (EsiRequestException $e) {
                // Bounded lock contention must defer, never allow extra calls.
                return $e->outcome === 'coordination' ? 'deferred' : 'unexpected';
            }
        });
        $this->assertSame(3, count(array_filter($results, fn ($result) => $result === 'reserved')), json_encode($results));
        $this->assertSame(9, count(array_filter($results, fn ($result) => in_array($result, ['denied', 'deferred'], true))), json_encode($results));
        $this->assertSame(0, app(EsiRateLimiter::class)->remaining('char-notification', '123'));
    }

    public function test_concurrent_expired_token_refresh_calls_sso_once_and_reloads_rotation(): void
    {
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'esi-refresh-');
        config(['database.connections.esi_refresh_test' => ['driver' => 'sqlite', 'database' => $this->databaseFile, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]]);
        DB::connection('esi_refresh_test')->getSchemaBuilder()->create('characters', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id')->primary();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at');
            $table->boolean('is_esi_active');
            $table->timestamps();
        });
        $character = (new Character)->setConnection('esi_refresh_test');
        $character->fill(['character_id' => 123, 'access_token' => 'old-access', 'refresh_token' => 'old-refresh', 'token_expires_at' => now()->subMinute(), 'is_esi_active' => true])->save();
        $store = app(EsiRateLimiter::class)->store();
        $store->put('sso_calls', 0, 60);
        Http::fake(function () {
            app(EsiRateLimiter::class)->store()->increment('sso_calls');
            usleep(250000);

            return Http::response(['access_token' => 'rotated-access', 'refresh_token' => 'rotated-refresh', 'expires_in' => 1199], 200);
        });
        DB::purge('esi_refresh_test');
        $results = $this->concurrent(4, function () use ($character) {
            app(EsiAuthClient::class)->refreshCharacterTokenOrFail($character);

            return $character->refresh_token;
        });
        $this->assertSame(array_fill(0, 4, 'rotated-refresh'), $results);
        $this->assertSame(1, (int) $store->get('sso_calls'));
    }
}
