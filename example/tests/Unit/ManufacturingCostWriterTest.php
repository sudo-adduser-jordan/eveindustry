<?php

namespace Tests\Unit;

use App\Jobs\ManufacturingCostWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

class ManufacturingCostWriterTest extends TestCase
{
    public function test_it_recognises_mysql_deadlocks_for_bounded_retry(): void
    {
        $deadlock = $this->queryException('insert into manufacturing_costs values (?)', ['large-bound-value'], ['40001', 1213, 'Deadlock']);
        $duplicateKey = $this->queryException('insert into manufacturing_costs values (?)', ['large-bound-value'], ['23000', 1062, 'Duplicate']);

        $this->assertTrue(ManufacturingCostWriter::isDeadlock($deadlock));
        $this->assertFalse(ManufacturingCostWriter::isDeadlock($duplicateKey));
    }

    public function test_it_retries_deadlock_transactions_then_returns_on_success(): void
    {
        $deadlock = $this->queryException('insert into manufacturing_costs values (?)', ['large-bound-value'], ['40001', 1213, 'Deadlock']);
        $callCount = 0;
        $table = \Mockery::mock();
        $table->shouldReceive('upsert')->once()->andReturn(1);
        DB::shouldReceive('table')->once()->with('manufacturing_costs')->andReturn($table);

        DB::shouldReceive('transaction')
            ->times(3)
            ->andReturnUsing(function (callable $callback, int $attempts) use (&$callCount, $deadlock) {
                $this->assertSame(1, $attempts, 'Each retry starts a fresh transaction.');
                $callCount++;

                if ($callCount < 3) {
                    throw $deadlock;
                }

                return $callback();
            });
        Log::shouldReceive('warning')->twice();
        Log::shouldReceive('error')->never();

        app(ManufacturingCostWriter::class)->upsert(10000002, []);

        $this->assertSame(3, $callCount);
    }

    public function test_it_throws_a_bounded_failure_after_deadlock_retries_are_exhausted(): void
    {
        $deadlock = $this->queryException(
            "insert into manufacturing_costs values ('sensitive-row-value')",
            ['sensitive-row-value'],
            ['40001', 1213, 'Deadlock']
        );

        DB::shouldReceive('transaction')->times(3)->andThrow($deadlock);
        Log::shouldReceive('warning')->twice();
        Log::shouldReceive('error')->once();

        try {
            app(ManufacturingCostWriter::class)->upsert(10000002, array_fill(0, 40, []));
            $this->fail('The writer must propagate an exhausted database failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('SQLSTATE 40001', $exception->getMessage());
            $this->assertStringContainsString('40 rows', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive-row-value', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_safe_failure_message_keeps_database_codes_without_sql_or_bindings(): void
    {
        $exception = $this->queryException(
            "insert into manufacturing_costs values ('sensitive-row-value')",
            ['sensitive-row-value'],
            ['HY000', 1205, 'Lock wait timeout']
        );

        $message = ManufacturingCostWriter::safeFailureMessage(10000002, 40, $exception);

        $this->assertStringContainsString('region 10000002', $message);
        $this->assertStringContainsString('40 rows', $message);
        $this->assertStringContainsString('SQLSTATE HY000', $message);
        $this->assertStringContainsString('driver code 1205', $message);
        $this->assertStringNotContainsString('insert into', $message);
        $this->assertStringNotContainsString('sensitive-row-value', $message);
    }

    /** @param array{0: string, 1: int, 2: string} $errorInfo */
    private function queryException(string $sql, array $bindings, array $errorInfo): QueryException
    {
        $previous = new PDOException($errorInfo[2]);
        $previous->errorInfo = $errorInfo;

        return new QueryException('mysql', $sql, $bindings, $previous);
    }
}
