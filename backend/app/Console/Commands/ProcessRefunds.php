<?php

namespace App\Console\Commands;

use App\Enums\RefundStatus;
use App\Jobs\ProcessRefundJob;
use App\Models\Refund;
use Illuminate\Console\Command;

class ProcessRefunds extends Command
{
    protected $signature = 'refunds:process {--limit=50}';

    protected $description = '補撿已核准但還沒退款的退款（到了重試時間的，或佇列遺失的）';

    public function handle(): int
    {
        $due = Refund::query()
            ->where('status', RefundStatus::Approved)
            ->where(fn ($q) => $q
                // 無法確認結果、排了下次嘗試時間的，到時間就補撿
                ->where('next_attempt_at', '<=', now())
                // 核准後超過 2 分鐘還沒動靜（佇列遺失或 worker 當掉）也補撿
                ->orWhere(fn ($q) => $q->whereNull('next_attempt_at')->where('updated_at', '<=', now()->subMinutes(2))))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($due as $id) {
            // execute() 會再次檢查狀態與時間，重複派送是安全的
            ProcessRefundJob::dispatch($id);
        }

        $this->info("補撿 {$due->count()} 筆退款");

        return self::SUCCESS;
    }
}
