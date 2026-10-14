<?php

namespace App\Jobs;

use App\Refunds\RefundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * 向金流商退款。只試一次（$tries = 1）：
 * 「無法確認結果」要不要再試、什麼時候再試，由 RefundService 依資料庫裡的狀態決定，
 * 不交給佇列自己重跑——佇列不知道上一次其實有沒有退成功，盲目重跑可能退兩次。
 * 沒處理完的由排程 refunds:process 補撿。
 */
class ProcessRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $refundId) {}

    public function handle(RefundService $refunds): void
    {
        $refunds->execute($this->refundId);
    }
}
