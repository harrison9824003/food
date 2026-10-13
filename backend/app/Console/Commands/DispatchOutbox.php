<?php

namespace App\Console\Commands;

use App\Erp\OutboxDispatcher;
use Illuminate\Console\Command;

class DispatchOutbox extends Command
{
    protected $signature = 'outbox:dispatch {--budget=50 : 最多跑幾秒}';

    protected $description = '把 outbox 裡待送的事件送去 ERP（失敗以指數退避重試，超過上限進入失敗清單）';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $summary = $dispatcher->run((int) $this->option('budget'));

        $this->info("送出 {$summary['sent']}、等待重試 {$summary['retrying']}、失敗 {$summary['failed']}");

        return self::SUCCESS;
    }
}
