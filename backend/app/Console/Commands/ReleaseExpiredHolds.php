<?php

namespace App\Console\Commands;

use App\Payments\HoldReleaseService;
use Illuminate\Console\Command;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'reservations:release-expired {--limit=200 : 單次最多處理幾筆}';

    protected $description = '釋放逾時未付款的暫時保留，歸還名額與桌位（釋放前先向綠界對帳）';

    public function handle(HoldReleaseService $service): int
    {
        $summary = $service->releaseExpired((int) $this->option('limit'));

        $this->info("釋放 {$summary['released']} 筆、期間已付款 {$summary['paid_meanwhile']} 筆、等待對帳 {$summary['skipped']} 筆");

        return self::SUCCESS;
    }
}
