<?php

namespace App\Console\Commands;

use App\Models\DiningTable;
use App\Models\Reservation;
use App\Models\Slot;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadtestPrepare extends Command
{
    protected $signature = 'loadtest:prepare
        {--capacity=50 : 每個時段的名額}
        {--slots=1 : 時段數量（明天 12:00 起，每 30 分鐘一個）}
        {--tables=100 : 桌位數量（每張 1~2 人）}
        {--json : 只輸出 JSON，供腳本解析}';

    protected $description = '建立／重置壓測專用的門市與時段（會清掉該門市先前的壓測訂位）';

    /** 壓測門市的固定名稱，重複執行時靠它找到同一間並重置。 */
    private const STORE_NAME = '[壓測] 壓測門市';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('正式環境不可執行壓測準備');

            return self::FAILURE;
        }

        $capacity = (int) $this->option('capacity');
        $slotCount = (int) $this->option('slots');
        $tableCount = (int) $this->option('tables');

        $store = Store::updateOrCreate(['name' => self::STORE_NAME], [
            'is_active' => true,
            'advance_days' => 7,
            'cutoff_minutes' => 0,
            'slot_capacity_default' => $capacity,
            'deposit_per_person' => 0, // 免訂金：訂位直接 confirmed，壓測不牽涉付款
        ]);

        DB::transaction(function () use ($store, $capacity, $slotCount, $tableCount, &$slotIds) {
            // 只清這間壓測門市的資料，不會碰到真正的門市
            $oldSlotIds = Slot::where('store_id', $store->id)->pluck('id');
            DB::table('reservation_tables')->whereIn('slot_id', $oldSlotIds)->delete();
            Reservation::whereIn('slot_id', $oldSlotIds)->delete();
            Slot::whereIn('id', $oldSlotIds)->delete();

            for ($i = 1; $i <= $tableCount; $i++) {
                DiningTable::updateOrCreate(
                    ['store_id' => $store->id, 'code' => sprintf('L%03d', $i)],
                    ['min_seats' => 1, 'max_seats' => 2, 'is_combinable' => false, 'is_active' => true],
                );
            }
            // 桌位數量比上次少時，多出來的停用，避免影響這次的桌位數
            DiningTable::where('store_id', $store->id)->where('code', '>', sprintf('L%03d', $tableCount))->update(['is_active' => false]);

            $slotIds = [];
            $date = today()->addDay()->toDateString();
            for ($i = 0; $i < $slotCount; $i++) {
                $time = sprintf('%02d:%02d:00', 12 + intdiv($i * 30, 60), ($i * 30) % 60);
                $slotIds[] = Slot::create([
                    'store_id' => $store->id, 'slot_date' => $date, 'start_time' => $time,
                    'capacity' => $capacity, 'booked' => 0,
                ])->id;
            }
        });

        $result = [
            'store_id' => $store->id,
            'date' => today()->addDay()->toDateString(),
            'slot_ids' => $slotIds,
            'capacity' => $capacity,
            'tables' => $tableCount,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result));
        } else {
            $this->info("壓測門市 #{$store->id} 已就緒：{$slotCount} 個時段（名額 {$capacity}）、{$tableCount} 張桌位");
            $this->line('slot_ids: '.implode(',', $slotIds));
        }

        return self::SUCCESS;
    }
}
