<?php

namespace Tests\Concerns;

use App\Models\DiningTable;
use App\Models\Slot;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * 訂位測試的共用場景：一間可訂位的門市、幾張桌子、明天 12:00 的一個時段。
 * 「今天」固定為 2026-10-05（週一），所以時段日期是 2026-10-06。
 */
trait CreatesBookableStore
{
    use CreatesStoreSchedule;

    protected Store $store;

    protected Slot $slot;

    /**
     * @param  array<string, mixed>  $storeAttributes
     */
    protected function setUpBookable(array $storeAttributes = [], int $capacity = 20, int $tables = 5): void
    {
        $this->freezeToday();

        $this->store = Store::factory()->create($storeAttributes + [
            'advance_days' => 14,
            'cutoff_minutes' => 60,
            'deposit_per_person' => 0,
        ]);

        for ($i = 1; $i <= $tables; $i++) {
            DiningTable::create([
                'store_id' => $this->store->id, 'code' => "T{$i}",
                'min_seats' => 1, 'max_seats' => 4, 'is_combinable' => false,
            ]);
        }

        $this->slot = $this->makeSlot($this->store, '2026-10-06', '12:00:00', $capacity);
    }

    protected function makeSlot(Store $store, string $date, string $time, int $capacity = 20, int $booked = 0): Slot
    {
        return Slot::create([
            'store_id' => $store->id, 'slot_date' => $date, 'start_time' => $time,
            'capacity' => $capacity, 'booked' => $booked,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'slot_id' => $this->slot->id,
            'party_size' => 2,
            'customer_name' => '王小明',
            'phone' => '0912345678',
        ], $overrides);
    }

    /** 把指定桌子在指定時段標記為已被佔用（模擬已有訂位）。 */
    protected function occupy(Slot $slot, DiningTable $table): void
    {
        $reservationId = DB::table('reservations')->insertGetId([
            'code' => strtoupper(substr(md5((string) microtime(true).$table->id), 0, 8)),
            'store_id' => $slot->store_id, 'slot_id' => $slot->id,
            'customer_name' => '佔位', 'phone' => '0900000000', 'party_size' => 1,
            'status' => 'confirmed', 'payment_status' => 'not_required',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('reservation_tables')->insert([
            'reservation_id' => $reservationId, 'table_id' => $table->id, 'slot_id' => $slot->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
