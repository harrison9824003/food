<?php

namespace App\Console\Commands;

use App\Payments\PaymentReconciler;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--limit=100 : 單次最多處理幾筆}';

    protected $description = '對帳補償：向綠界查詢建立一陣子仍未收到通知的付款（冪等）';

    public function handle(PaymentReconciler $reconciler): int
    {
        $summary = $reconciler->reconcilePending((int) $this->option('limit'));

        $this->info("檢查 {$summary['checked']} 筆：補單 {$summary['paid']}、放棄 {$summary['abandoned']}、無法確認 {$summary['unknown']}");

        return self::SUCCESS;
    }
}
