<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\SlotGenerator;
use Illuminate\Console\Command;

class GenerateSlots extends Command
{
    protected $signature = 'slots:generate {--store= : 只處理指定門市 ID}';

    protected $description = '依營業時間為所有啟用中的門市補齊未來可訂時段（冪等）';

    public function handle(SlotGenerator $generator): int
    {
        $stores = Store::query()
            ->where('is_active', true)
            ->when($this->option('store'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($stores as $store) {
            $result = $generator->sync($store);
            $this->info("{$store->name}：新增 {$result['created']}、刪除 {$result['deleted']}");
        }

        return self::SUCCESS;
    }
}
