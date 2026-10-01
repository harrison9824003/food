<?php

namespace Tests\Unit;

use App\Support\DeadlockRetrier;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class DeadlockRetrierTest extends TestCase
{
    private function queryException(string $sqlState, int $driverCode): QueryException
    {
        $pdo = new PDOException('simulated');
        $pdo->errorInfo = [$sqlState, $driverCode, 'simulated'];

        return new QueryException('mysql', 'UPDATE slots ...', [], $pdo);
    }

    private function deadlock(): QueryException
    {
        return $this->queryException('40001', 1213);
    }

    public function test_returns_the_result_without_retrying_when_nothing_fails(): void
    {
        $calls = 0;

        $result = (new DeadlockRetrier(3))->run(function () use (&$calls) {
            $calls++;

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);
    }

    public function test_retries_after_a_deadlock_and_succeeds(): void
    {
        $calls = 0;

        $result = (new DeadlockRetrier(3))->run(function () use (&$calls) {
            if (++$calls < 3) {
                throw $this->deadlock();
            }

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(3, $calls);
    }

    public function test_gives_up_after_the_maximum_number_of_attempts(): void
    {
        $calls = 0;

        try {
            (new DeadlockRetrier(3))->run(function () use (&$calls) {
                $calls++;
                throw $this->deadlock();
            });
            $this->fail('應該把死鎖例外拋出來');
        } catch (QueryException $e) {
            $this->assertTrue(DeadlockRetrier::isDeadlock($e));
        }

        $this->assertSame(3, $calls);
    }

    public function test_does_not_retry_other_database_errors(): void
    {
        $calls = 0;

        try {
            (new DeadlockRetrier(3))->run(function () use (&$calls) {
                $calls++;
                throw $this->queryException('23000', 1062); // 重複鍵，重試也沒用
            });
            $this->fail('應該直接拋出');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(1, $calls);
    }

    public function test_does_not_retry_non_database_exceptions(): void
    {
        $calls = 0;

        try {
            (new DeadlockRetrier(3))->run(function () use (&$calls) {
                $calls++;
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1, $calls);
    }

    public function test_detects_deadlocks_by_sqlstate_or_driver_code(): void
    {
        $this->assertTrue(DeadlockRetrier::isDeadlock($this->queryException('40001', 0)));
        $this->assertTrue(DeadlockRetrier::isDeadlock($this->queryException('HY000', 1213)));
        $this->assertFalse(DeadlockRetrier::isDeadlock($this->queryException('23000', 1062)));
        // 鎖等待逾時（1205）是另一回事，不屬於死鎖
        $this->assertFalse(DeadlockRetrier::isDeadlock($this->queryException('HY000', 1205)));
    }
}
