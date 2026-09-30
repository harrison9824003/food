<?php

namespace Tests\Feature\Admin;

use App\Models\DiningTable;
use App\Models\Slot;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoreSchedule;
use Tests\TestCase;

/**
 * 營業時間、公休日、桌位、時段名額。
 */
class StoreSettingsTest extends TestCase
{
    use CreatesStoreSchedule;
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeToday(); // 2026-10-05 週一
        $this->storeA = Store::factory()->create(['advance_days' => 2]);
        $this->storeB = Store::factory()->create(['advance_days' => 2]);
    }

    private function actingAsManagerA(): User
    {
        return tap(User::factory()->storeManager($this->storeA)->create(), fn ($u) => Sanctum::actingAs($u));
    }

    private function actingAsStaffA(): User
    {
        return tap(User::factory()->staff($this->storeA)->create(), fn ($u) => Sanctum::actingAs($u));
    }

    private function url(Store $store, string $path): string
    {
        return "/api/admin/stores/{$store->id}/{$path}";
    }

    // ── 營業時間 ────────────────────────────────────────────────────────

    public function test_business_hours_default_to_closed_for_all_seven_days(): void
    {
        $this->actingAsManagerA();

        $response = $this->getJson($this->url($this->storeA, 'business-hours'))->assertOk()->assertJsonCount(7);

        $this->assertSame([0, 1, 2, 3, 4, 5, 6], collect($response->json())->pluck('weekday')->all());
        $this->assertTrue(collect($response->json())->every(fn ($h) => $h['is_closed'] === true));
    }

    public function test_manager_can_save_business_hours_and_slots_are_generated(): void
    {
        $this->actingAsManagerA();

        $response = $this->putJson($this->url($this->storeA, 'business-hours'), $this->hoursPayload('11:00', '13:00', 30, closedWeekdays: [1]))
            ->assertOk()
            ->assertJsonPath('hours.2.open_time', '11:00')
            ->assertJsonPath('hours.1.is_closed', true)
            ->assertJsonPath('hours.1.open_time', null);

        // 10/05 是週一（公休），只剩 10/06、10/07 兩天 × 4 個時段
        $this->assertSame(8, $response->json('slots.created'));
        $this->assertSame(8, Slot::where('store_id', $this->storeA->id)->count());
    }

    public function test_saving_business_hours_twice_does_not_duplicate_days(): void
    {
        $this->actingAsManagerA();

        $this->putJson($this->url($this->storeA, 'business-hours'), $this->hoursPayload())->assertOk();
        $this->putJson($this->url($this->storeA, 'business-hours'), $this->hoursPayload('10:00', '12:00'))->assertOk();

        $this->assertSame(7, $this->storeA->businessHours()->count());
    }

    public function test_business_hours_validation(): void
    {
        $this->actingAsManagerA();
        $url = $this->url($this->storeA, 'business-hours');

        // 不是 7 天
        $this->putJson($url, ['hours' => array_slice($this->hoursPayload()['hours'], 0, 6)])
            ->assertStatus(422)->assertJsonValidationErrors('hours', 'errors');

        // 打烊早於開店
        $this->putJson($url, $this->hoursPayload('13:00', '11:00'))
            ->assertStatus(422)->assertJsonValidationErrors('hours.0.close_time', 'errors');

        // 時段間隔只允許 15 / 30 / 60
        $this->putJson($url, $this->hoursPayload('11:00', '13:00', 20))
            ->assertStatus(422)->assertJsonValidationErrors('hours.0.slot_minutes', 'errors');

        // 營業日缺時間
        $payload = $this->hoursPayload();
        unset($payload['hours'][3]['open_time']);
        $this->putJson($url, $payload)->assertStatus(422)->assertJsonValidationErrors('hours.3.open_time', 'errors');

        // weekday 重複
        $payload = $this->hoursPayload();
        $payload['hours'][1]['weekday'] = 0;
        $this->putJson($url, $payload)->assertStatus(422);
    }

    public function test_staff_can_read_but_not_write_business_hours(): void
    {
        $this->actingAsStaffA();

        $this->getJson($this->url($this->storeA, 'business-hours'))->assertOk();
        $this->putJson($this->url($this->storeA, 'business-hours'), $this->hoursPayload())->assertStatus(403);
    }

    public function test_manager_cannot_touch_other_stores_business_hours(): void
    {
        $this->actingAsManagerA();

        $this->getJson($this->url($this->storeB, 'business-hours'))->assertStatus(403);
        $this->putJson($this->url($this->storeB, 'business-hours'), $this->hoursPayload())->assertStatus(403);
    }

    // ── 公休日 ──────────────────────────────────────────────────────────

    public function test_closing_a_date_removes_its_unbooked_slots(): void
    {
        $this->openAllWeek($this->storeA);
        app(\App\Services\SlotGenerator::class)->sync($this->storeA);
        $this->actingAsManagerA();

        $this->postJson($this->url($this->storeA, 'closed-dates'), ['closed_date' => '2026-10-06', 'reason' => '員工旅遊'])
            ->assertCreated()
            ->assertJsonPath('closed_date', '2026-10-06')
            ->assertJsonPath('reason', '員工旅遊');

        $this->assertSame(0, Slot::where('store_id', $this->storeA->id)->where('slot_date', '2026-10-06')->count());
        $this->assertSame(8, Slot::where('store_id', $this->storeA->id)->count());
    }

    public function test_cannot_close_a_date_that_already_has_reservations(): void
    {
        $this->openAllWeek($this->storeA);
        app(\App\Services\SlotGenerator::class)->sync($this->storeA);
        Slot::where('store_id', $this->storeA->id)->where('slot_date', '2026-10-06')->where('start_time', '12:00:00')->update(['booked' => 3]);
        $this->actingAsManagerA();

        $this->postJson($this->url($this->storeA, 'closed-dates'), ['closed_date' => '2026-10-06'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CLOSED_DATE_HAS_RESERVATIONS');

        $this->assertDatabaseMissing('store_closed_dates', ['store_id' => $this->storeA->id]);
        $this->assertSame(4, Slot::where('store_id', $this->storeA->id)->where('slot_date', '2026-10-06')->count());
    }

    public function test_closed_date_validation(): void
    {
        $this->actingAsManagerA();
        $url = $this->url($this->storeA, 'closed-dates');
        $this->storeA->closedDates()->create(['closed_date' => '2026-10-10']);

        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('closed_date', 'errors');
        $this->postJson($url, ['closed_date' => '2026-10-04'])->assertStatus(422)->assertJsonValidationErrors('closed_date', 'errors'); // 過去
        $this->postJson($url, ['closed_date' => '2026-10-10'])->assertStatus(422)->assertJsonValidationErrors('closed_date', 'errors'); // 重複
        $this->postJson($url, ['closed_date' => '10/12/2026'])->assertStatus(422)->assertJsonValidationErrors('closed_date', 'errors'); // 格式
    }

    public function test_same_date_can_be_closed_in_different_stores(): void
    {
        $this->storeB->closedDates()->create(['closed_date' => '2026-10-06']);
        $this->actingAsManagerA();

        $this->postJson($this->url($this->storeA, 'closed-dates'), ['closed_date' => '2026-10-06'])->assertCreated();
    }

    public function test_reopening_a_closed_date_restores_its_slots(): void
    {
        $this->openAllWeek($this->storeA);
        $this->actingAsManagerA();
        $closed = $this->storeA->closedDates()->create(['closed_date' => '2026-10-06']);

        $this->deleteJson($this->url($this->storeA, "closed-dates/{$closed->id}"))->assertOk();

        $this->assertDatabaseMissing('store_closed_dates', ['id' => $closed->id]);
        $this->assertSame(4, Slot::where('store_id', $this->storeA->id)->where('slot_date', '2026-10-06')->count());
    }

    public function test_closed_date_list_only_shows_upcoming_dates(): void
    {
        $this->storeA->closedDates()->create(['closed_date' => '2026-10-01']); // 過去
        $this->storeA->closedDates()->create(['closed_date' => '2026-10-20']);
        $this->actingAsStaffA();

        $this->getJson($this->url($this->storeA, 'closed-dates'))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.closed_date', '2026-10-20');
    }

    public function test_staff_cannot_modify_closed_dates_and_other_store_is_protected(): void
    {
        $closed = $this->storeA->closedDates()->create(['closed_date' => '2026-10-20']);
        $foreign = $this->storeB->closedDates()->create(['closed_date' => '2026-10-21']);

        $this->actingAsStaffA();
        $this->postJson($this->url($this->storeA, 'closed-dates'), ['closed_date' => '2026-10-22'])->assertStatus(403);
        $this->deleteJson($this->url($this->storeA, "closed-dates/{$closed->id}"))->assertStatus(403);

        $this->actingAsManagerA();
        // 用自己門市的網址去刪別間門市的公休日：綁定限定在該門市底下，找不到 → 404
        $this->deleteJson($this->url($this->storeA, "closed-dates/{$foreign->id}"))->assertStatus(404);
        $this->assertDatabaseHas('store_closed_dates', ['id' => $foreign->id]);
    }

    // ── 桌位 ────────────────────────────────────────────────────────────

    public function test_manager_can_create_list_and_update_tables(): void
    {
        $this->actingAsManagerA();

        $this->postJson($this->url($this->storeA, 'tables'), ['code' => 'B2', 'min_seats' => 2, 'max_seats' => 4, 'is_combinable' => true])
            ->assertCreated()
            ->assertJsonPath('code', 'B2')
            ->assertJsonPath('is_combinable', true)
            ->assertJsonPath('is_active', true);
        $this->postJson($this->url($this->storeA, 'tables'), ['code' => 'A1', 'min_seats' => 1, 'max_seats' => 2])->assertCreated();

        $this->getJson($this->url($this->storeA, 'tables'))->assertOk()->assertJsonCount(2)->assertJsonPath('0.code', 'A1');

        $table = DiningTable::where('code', 'B2')->first();
        $this->patchJson($this->url($this->storeA, "tables/{$table->id}"), ['max_seats' => 6, 'is_active' => false])
            ->assertOk()->assertJsonPath('max_seats', 6)->assertJsonPath('is_active', false);
    }

    public function test_table_validation(): void
    {
        $this->actingAsManagerA();
        $url = $this->url($this->storeA, 'tables');
        DiningTable::create(['store_id' => $this->storeA->id, 'code' => 'A1', 'min_seats' => 1, 'max_seats' => 4]);

        $this->postJson($url, ['code' => 'A1', 'min_seats' => 1, 'max_seats' => 4])->assertStatus(422)->assertJsonValidationErrors('code', 'errors');
        $this->postJson($url, ['code' => 'A2', 'min_seats' => 5, 'max_seats' => 2])->assertStatus(422)->assertJsonValidationErrors('max_seats', 'errors');
        $this->postJson($url, ['code' => 'A2', 'min_seats' => 0, 'max_seats' => 2])->assertStatus(422)->assertJsonValidationErrors('min_seats', 'errors');
        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['code', 'min_seats', 'max_seats'], 'errors');
    }

    public function test_same_table_code_is_allowed_in_different_stores(): void
    {
        DiningTable::create(['store_id' => $this->storeB->id, 'code' => 'A1', 'min_seats' => 1, 'max_seats' => 4]);
        $this->actingAsManagerA();

        $this->postJson($this->url($this->storeA, 'tables'), ['code' => 'A1', 'min_seats' => 1, 'max_seats' => 4])->assertCreated();
    }

    public function test_update_validates_min_max_against_current_values(): void
    {
        $table = DiningTable::create(['store_id' => $this->storeA->id, 'code' => 'A1', 'min_seats' => 2, 'max_seats' => 4]);
        $this->actingAsManagerA();

        // 只把上限降到 1，但下限現值是 2
        $this->patchJson($this->url($this->storeA, "tables/{$table->id}"), ['max_seats' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('max_seats', 'errors');
        // 只把下限拉到 5，但上限現值是 4
        $this->patchJson($this->url($this->storeA, "tables/{$table->id}"), ['min_seats' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('max_seats', 'errors');
    }

    public function test_update_can_keep_own_code(): void
    {
        $table = DiningTable::create(['store_id' => $this->storeA->id, 'code' => 'A1', 'min_seats' => 2, 'max_seats' => 4]);
        $this->actingAsManagerA();

        $this->patchJson($this->url($this->storeA, "tables/{$table->id}"), ['code' => 'A1', 'max_seats' => 5])->assertOk();
    }

    public function test_tables_are_protected_across_stores_and_roles(): void
    {
        $foreign = DiningTable::create(['store_id' => $this->storeB->id, 'code' => 'Z9', 'min_seats' => 1, 'max_seats' => 2]);

        $this->actingAsStaffA();
        $this->getJson($this->url($this->storeA, 'tables'))->assertOk();
        $this->postJson($this->url($this->storeA, 'tables'), ['code' => 'A1', 'min_seats' => 1, 'max_seats' => 2])->assertStatus(403);

        $this->actingAsManagerA();
        // 別間門市的桌位掛在自己門市網址下 → 404；直接打對方門市網址 → 403
        $this->patchJson($this->url($this->storeA, "tables/{$foreign->id}"), ['max_seats' => 9])->assertStatus(404);
        $this->patchJson($this->url($this->storeB, "tables/{$foreign->id}"), ['max_seats' => 9])->assertStatus(403);
        $this->assertSame(2, $foreign->fresh()->max_seats);
    }

    // ── 時段名額 ────────────────────────────────────────────────────────

    private function makeSlot(Store $store, string $date = '2026-10-06', string $time = '12:00:00', int $capacity = 20, int $booked = 0): Slot
    {
        return Slot::create(['store_id' => $store->id, 'slot_date' => $date, 'start_time' => $time, 'capacity' => $capacity, 'booked' => $booked]);
    }

    public function test_slot_list_requires_a_date_and_shows_remaining(): void
    {
        $this->makeSlot($this->storeA, time: '12:30:00', capacity: 10, booked: 4);
        $this->makeSlot($this->storeA, time: '11:30:00');
        $this->makeSlot($this->storeA, date: '2026-10-07');
        $this->actingAsStaffA();

        $this->getJson($this->url($this->storeA, 'slots'))->assertStatus(422)->assertJsonValidationErrors('date', 'errors');

        $this->getJson($this->url($this->storeA, 'slots?date=2026-10-06'))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.start_time', '11:30')
            ->assertJsonPath('1.start_time', '12:30')
            ->assertJsonPath('1.booked', 4)
            ->assertJsonPath('1.remaining', 6);
    }

    public function test_manager_can_change_slot_capacity(): void
    {
        $slot = $this->makeSlot($this->storeA, capacity: 20, booked: 5);
        $this->actingAsManagerA();

        $this->patchJson($this->url($this->storeA, "slots/{$slot->id}"), ['capacity' => 8])
            ->assertOk()->assertJsonPath('capacity', 8)->assertJsonPath('remaining', 3);
        $this->assertSame(8, $slot->fresh()->capacity);
    }

    public function test_capacity_cannot_go_below_booked(): void
    {
        $slot = $this->makeSlot($this->storeA, capacity: 20, booked: 5);
        $this->actingAsManagerA();

        $this->patchJson($this->url($this->storeA, "slots/{$slot->id}"), ['capacity' => 4])
            ->assertStatus(409)->assertJsonPath('code', 'CAPACITY_BELOW_BOOKED');
        $this->assertSame(20, $slot->fresh()->capacity);

        // 剛好等於已訂人數是允許的（該時段客滿）
        $this->patchJson($this->url($this->storeA, "slots/{$slot->id}"), ['capacity' => 5])->assertOk();
    }

    public function test_setting_the_same_capacity_is_not_a_conflict(): void
    {
        $slot = $this->makeSlot($this->storeA, capacity: 20, booked: 5);
        $this->actingAsManagerA();

        $this->patchJson($this->url($this->storeA, "slots/{$slot->id}"), ['capacity' => 20])->assertOk();
    }

    public function test_capacity_can_be_zero_to_pause_a_slot_but_not_negative(): void
    {
        $slot = $this->makeSlot($this->storeA);
        $this->actingAsManagerA();

        $this->patchJson($this->url($this->storeA, "slots/{$slot->id}"), ['capacity' => 0])->assertOk()->assertJsonPath('remaining', 0);
        $this->patchJson($this->url($this->storeA, "slots/{$slot->id}"), ['capacity' => -1])->assertStatus(422);
    }

    public function test_slot_updates_are_protected_across_stores_and_roles(): void
    {
        $mine = $this->makeSlot($this->storeA);
        $foreign = $this->makeSlot($this->storeB);

        $this->actingAsStaffA();
        $this->patchJson($this->url($this->storeA, "slots/{$mine->id}"), ['capacity' => 5])->assertStatus(403);

        $this->actingAsManagerA();
        $this->patchJson($this->url($this->storeA, "slots/{$foreign->id}"), ['capacity' => 5])->assertStatus(404);
        $this->patchJson($this->url($this->storeB, "slots/{$foreign->id}"), ['capacity' => 5])->assertStatus(403);
        $this->assertSame(20, $foreign->fresh()->capacity);
    }

    public function test_manual_generate_endpoint(): void
    {
        $this->openAllWeek($this->storeA);
        $this->actingAsManagerA();

        $this->postJson($this->url($this->storeA, 'slots/generate'))->assertOk()->assertJsonPath('created', 12);
        $this->postJson($this->url($this->storeA, 'slots/generate'))->assertOk()->assertJsonPath('created', 0);

        $this->actingAsStaffA();
        $this->postJson($this->url($this->storeA, 'slots/generate'))->assertStatus(403);
    }
}
