<?php

namespace Tests\Feature;

use App\Models\Slot;
use App\Models\Store;
use App\Services\SlotGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStoreSchedule;
use Tests\TestCase;

class SlotGenerationTest extends TestCase
{
    use CreatesStoreSchedule;
    use RefreshDatabase;

    private SlotGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeToday(); // 2026-10-05 週一
        $this->generator = app(SlotGenerator::class);
    }

    private function store(array $attributes = []): Store
    {
        return Store::factory()->create(['advance_days' => 2, 'slot_capacity_default' => 20] + $attributes);
    }

    public function test_generates_slots_for_the_whole_open_window(): void
    {
        $store = $this->store();
        $this->openAllWeek($store, '11:00', '13:00', 30);

        $result = $this->generator->sync($store);

        // 今天起連續 3 天（今天 + advance_days=2），每天 11:00 / 11:30 / 12:00 / 12:30 共 4 個時段
        $this->assertSame(12, $result['created']);
        $this->assertSame(12, Slot::where('store_id', $store->id)->count());
        $this->assertSame(
            ['11:00:00', '11:30:00', '12:00:00', '12:30:00'],
            Slot::where('slot_date', '2026-10-05')->orderBy('start_time')->pluck('start_time')->all(),
        );
        $this->assertSame(20, Slot::first()->capacity);
        $this->assertSame(0, Slot::first()->booked);
    }

    public function test_skips_weekly_closed_days_and_specific_closed_dates(): void
    {
        $store = $this->store();
        $this->openAllWeek($store);
        $store->businessHours()->where('weekday', 1)->update(['is_closed' => true]); // 週一公休
        $store->closedDates()->create(['closed_date' => '2026-10-07']);               // 週三特別公休

        $this->generator->sync($store);

        $this->assertSame(['2026-10-06'], Slot::distinct()->pluck('slot_date')->map->toDateString()->all());
    }

    public function test_is_idempotent(): void
    {
        $store = $this->store();
        $this->openAllWeek($store);

        $this->generator->sync($store);
        $second = $this->generator->sync($store);

        $this->assertSame(['created' => 0, 'deleted' => 0], $second);
        $this->assertSame(12, Slot::count());
    }

    public function test_never_touches_existing_slots(): void
    {
        $store = $this->store();
        $this->openAllWeek($store);
        $this->generator->sync($store);

        $slot = Slot::where('slot_date', '2026-10-05')->where('start_time', '11:00:00')->first();
        $slot->update(['capacity' => 7, 'booked' => 5]);

        $store->update(['slot_capacity_default' => 99]);
        $this->generator->sync($store);

        $slot->refresh();
        $this->assertSame(7, $slot->capacity);
        $this->assertSame(5, $slot->booked);
    }

    public function test_removes_stale_unbooked_slots_but_keeps_booked_ones(): void
    {
        $store = $this->store();
        $this->openAllWeek($store, '11:00', '13:00', 30);
        $this->generator->sync($store);

        $booked = Slot::where('slot_date', '2026-10-06')->where('start_time', '12:00:00')->first();
        $booked->update(['booked' => 2]);

        // 營業時間縮短成只到 12:00 打烊：12:00、12:30 不再屬於營業時間
        $this->openAllWeek($store, '11:00', '12:00', 30);
        $result = $this->generator->sync($store);

        $this->assertSame(5, $result['deleted']); // 3 天各 2 個過期時段共 6 個，扣掉有人訂的 1 個
        $this->assertNotNull(Slot::find($booked->id), '已有人訂的時段不可被刪除');
        $this->assertSame(0, Slot::where('slot_date', '2026-10-05')->where('start_time', '>=', '12:00:00')->count());
    }

    public function test_does_not_delete_unbooked_slot_that_still_has_reservation_history(): void
    {
        $store = $this->store();
        $this->openAllWeek($store, '11:00', '13:00', 30);
        $this->generator->sync($store);

        // 曾有人訂、後來取消：booked 歸零，但 reservations 仍以外鍵參照這個時段
        $slot = Slot::where('slot_date', '2026-10-06')->where('start_time', '12:30:00')->first();
        DB::table('reservations')->insert([
            'code' => 'R-HISTORY', 'store_id' => $store->id, 'slot_id' => $slot->id,
            'customer_name' => '測試', 'phone' => '0912345678', 'party_size' => 2,
            'status' => 'cancelled', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->openAllWeek($store, '11:00', '12:00', 30);
        $this->generator->sync($store); // 不可因外鍵而失敗，也不可刪掉這個時段

        $this->assertNotNull(Slot::find($slot->id));
    }

    public function test_inactive_store_gets_no_slots(): void
    {
        $store = $this->store(['is_active' => false]);
        $this->openAllWeek($store);

        $this->assertSame(['created' => 0, 'deleted' => 0], $this->generator->sync($store));
        $this->assertSame(0, Slot::count());
    }

    public function test_store_without_business_hours_gets_no_slots(): void
    {
        $this->generator->sync($this->store());

        $this->assertSame(0, Slot::count());
    }

    public function test_artisan_command_generates_for_active_stores_only(): void
    {
        $active = $this->store();
        $inactive = $this->store(['is_active' => false]);
        $this->openAllWeek($active);
        $this->openAllWeek($inactive);

        $this->artisan('slots:generate')->assertSuccessful();

        $this->assertSame(12, Slot::where('store_id', $active->id)->count());
        $this->assertSame(0, Slot::where('store_id', $inactive->id)->count());
    }
}
