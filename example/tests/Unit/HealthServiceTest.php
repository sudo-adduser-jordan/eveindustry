<?php

namespace Tests\Unit;

use App\Services\HealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthServiceTest extends TestCase
{
    public function test_ok_when_database_and_cache_answer(): void
    {
        $this->assertSame(
            ['status' => 'ok', 'checks' => ['database' => 'ok', 'cache' => 'ok']],
            (new HealthService)->check(),
        );
    }

    public function test_failed_when_the_database_throws(): void
    {
        DB::shouldReceive('select')->andThrow(new \RuntimeException('connection refused'));

        $result = (new HealthService)->check();

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $result['checks']['database']);
        $this->assertSame('ok', $result['checks']['cache']);
    }

    public function test_failed_when_the_cache_does_not_read_back(): void
    {
        Cache::shouldReceive('put')->andReturnTrue();
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('forget')->andReturnTrue();

        $result = (new HealthService)->check();

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $result['checks']['cache']);
    }
}
