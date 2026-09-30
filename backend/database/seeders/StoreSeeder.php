<?php

namespace Database\Seeders;

use App\Models\DiningTable;
use App\Models\Store;
use App\Services\SlotGenerator;
use Illuminate\Database\Seeder;

/**
 * 示範門市：營業時間、桌位，並直接展開未來的可訂時段。
 */
class StoreSeeder extends Seeder
{
    public function run(SlotGenerator $slots): void
    {
        $xinyi = Store::updateOrCreate(['name' => '信義店'], [
            'address' => '台北市信義區松高路 1 號',
            'phone' => '0227000001',
            'deposit_per_person' => 200,
        ]);

        $taichung = Store::updateOrCreate(['name' => '台中店'], [
            'address' => '台中市西區公益路 100 號',
            'phone' => '0422000002',
            'deposit_per_person' => 0,
            'advance_days' => 14,
        ]);

        foreach ([$xinyi, $taichung] as $store) {
            foreach (range(0, 6) as $weekday) {
                $store->businessHours()->updateOrCreate(['weekday' => $weekday], [
                    // 信義店週一公休；台中店全週營業
                    'is_closed' => $store->is($xinyi) && $weekday === 1,
                    'open_time' => ($store->is($xinyi) && $weekday === 1) ? null : '11:00',
                    'close_time' => ($store->is($xinyi) && $weekday === 1) ? null : '21:00',
                    'slot_minutes' => 30,
                ]);
            }

            foreach ([['A1', 1, 2, true], ['A2', 1, 2, true], ['B1', 2, 4, true], ['B2', 2, 4, false], ['C1', 4, 8, false]] as [$code, $min, $max, $combinable]) {
                DiningTable::updateOrCreate(
                    ['store_id' => $store->id, 'code' => $code],
                    ['min_seats' => $min, 'max_seats' => $max, 'is_combinable' => $combinable, 'is_active' => true],
                );
            }

            $slots->sync($store);
        }
    }
}
