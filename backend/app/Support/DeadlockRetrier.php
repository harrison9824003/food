<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * 遇到死鎖就重跑整段交易，有次數上限。
 *
 * 死鎖在高並發寫入下是正常現象，不是 bug：InnoDB 偵測到循環等待時，會挑一個交易當犧牲者
 * 直接回滾（SQLSTATE 40001 / 錯誤碼 1213），官方建議的處理方式就是讓應用層重試。
 *
 * 傳入的 callable 必須「包含完整的交易」（BEGIN 到 COMMIT），因為犧牲者的交易已經被資料庫回滾了，
 * 只重跑失敗的那一行沒有意義。
 */
class DeadlockRetrier
{
    public function __construct(private readonly ?int $maxAttempts = null) {}

    /**
     * @template T
     *
     * @param  callable(): T  $transaction
     * @return T
     */
    public function run(callable $transaction): mixed
    {
        $max = $this->maxAttempts ?? (int) config('booking.deadlock_attempts', 3);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $transaction();
            } catch (QueryException $e) {
                if (! self::isDeadlock($e) || $attempt >= $max) {
                    throw $e;
                }

                Log::warning('deadlock detected, retrying transaction', ['attempt' => $attempt, 'max' => $max]);

                // 加一點隨機延遲（jitter），避免兩個被重試的交易又同時撞在一起
                usleep(random_int(5_000, 25_000) * $attempt);
            }
        }
    }

    public static function isDeadlock(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '40001' || ($e->errorInfo[1] ?? null) === 1213;
    }
}
