<?php

namespace Tests\Feature\Booking;

use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesBookableStore;
use Tests\TestCase;

class ReservationCreationTest extends TestCase
{
    use CreatesBookableStore;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBookable();
    }

    private function book(array $overrides = [], array $headers = [])
    {
        return $this->withHeaders($headers)->postJson('/api/reservations', $this->bookingPayload($overrides));
    }

    public function test_free_store_reservation_is_confirmed_immediately(): void
    {
        $response = $this->book()->assertCreated()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('payment_status', 'not_required')
            ->assertJsonPath('deposit_amount', 0)
            ->assertJsonPath('hold_expires_at', null)
            ->assertJsonPath('date', '2026-10-06')
            ->assertJsonPath('time', '12:00')
            ->assertJsonPath('store.id', $this->store->id);

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $response->json('code'));
        $this->assertSame(2, $this->slot->fresh()->booked);
        $this->assertDatabaseCount('reservation_tables', 1);
        $this->assertDatabaseHas('reservation_tables', ['slot_id' => $this->slot->id]);
    }

    public function test_store_with_deposit_holds_the_slot_as_pending_payment(): void
    {
        $this->store->update(['deposit_per_person' => 200]);

        $this->book(['party_size' => 3])->assertCreated()
            ->assertJsonPath('status', 'pending_payment')
            ->assertJsonPath('payment_status', 'unpaid')
            ->assertJsonPath('deposit_amount', 600)
            ->assertJsonPath('hold_expires_at', now()->addMinutes(10)->toIso8601String());

        // 暫時保留也要佔名額，別人才不會在付款期間訂走
        $this->assertSame(3, $this->slot->fresh()->booked);
    }

    public function test_request_validation(): void
    {
        $this->postJson('/api/reservations', [])->assertStatus(422)
            ->assertJsonValidationErrors(['slot_id', 'party_size', 'customer_name', 'phone'], 'errors');

        $this->book(['phone' => '123'])->assertStatus(422)->assertJsonValidationErrors('phone', 'errors');
        $this->book(['phone' => '0212345678'])->assertStatus(422)->assertJsonValidationErrors('phone', 'errors');
        $this->book(['party_size' => 0])->assertStatus(422)->assertJsonValidationErrors('party_size', 'errors');
        $this->book(['party_size' => 13])->assertStatus(422)->assertJsonValidationErrors('party_size', 'errors');
        $this->book(['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('email', 'errors');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_unknown_slot_returns_404(): void
    {
        $this->book(['slot_id' => 999999])->assertStatus(404)->assertJsonPath('code', 'SLOT_NOT_FOUND');
    }

    public function test_slot_fills_up_and_then_rejects_further_bookings(): void
    {
        $this->slot->update(['capacity' => 4]);

        $this->book()->assertCreated();
        $this->book()->assertCreated();
        $this->book()->assertStatus(409)->assertJsonPath('code', 'SLOT_FULL');

        $this->assertSame(4, $this->slot->fresh()->booked);
        $this->assertSame(2, Reservation::count());
    }

    public function test_party_larger_than_remaining_is_rejected_even_if_some_seats_are_left(): void
    {
        $this->slot->update(['capacity' => 5, 'booked' => 3]);

        $this->book(['party_size' => 3])->assertStatus(409)->assertJsonPath('code', 'SLOT_FULL');
        $this->assertSame(3, $this->slot->fresh()->booked);
    }

    public function test_failed_table_allocation_rolls_back_the_capacity_it_took(): void
    {
        // 名額夠，但門市沒有任何桌子：名額已在交易內扣下去，分配桌位失敗後必須整筆回滾
        $this->store->diningTables()->delete();

        $this->book()->assertStatus(409)->assertJsonPath('code', 'NO_TABLE_AVAILABLE');

        $this->assertSame(0, $this->slot->fresh()->booked);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_tables', 0);
    }

    public function test_each_reservation_gets_a_different_table(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->book(['party_size' => 1])->assertCreated();
        }

        $this->assertSame(5, \DB::table('reservation_tables')->where('slot_id', $this->slot->id)->distinct()->count('table_id'));

        // 5 張桌子都用完了，名額還有剩也不能再訂
        $this->book(['party_size' => 1])->assertStatus(409)->assertJsonPath('code', 'NO_TABLE_AVAILABLE');
        $this->assertSame(5, $this->slot->fresh()->booked);
    }

    public function test_closed_date_blocks_booking(): void
    {
        $this->store->closedDates()->create(['closed_date' => '2026-10-06']);

        $this->book()->assertStatus(422)->assertJsonPath('code', 'DATE_CLOSED');
        $this->assertSame(0, $this->slot->fresh()->booked);
    }

    public function test_cutoff_blocks_booking(): void
    {
        // 截止 60 分鐘：12:00 的時段，11:00 之後就不能訂
        Carbon::setTestNow('2026-10-06 11:30:00');

        $this->book()->assertStatus(422)->assertJsonPath('code', 'CUTOFF_PASSED');

        Carbon::setTestNow('2026-10-06 10:59:00');
        $this->book()->assertCreated();
    }

    public function test_slot_beyond_the_open_window_is_rejected(): void
    {
        $far = $this->makeSlot($this->store, '2026-10-30', '12:00:00');

        $this->book(['slot_id' => $far->id])->assertStatus(422)->assertJsonPath('code', 'OUT_OF_WINDOW');
    }

    public function test_inactive_store_is_rejected(): void
    {
        $this->store->update(['is_active' => false]);

        $this->book()->assertStatus(422)->assertJsonPath('code', 'STORE_INACTIVE');
    }

    public function test_request_id_is_stored_on_the_reservation(): void
    {
        $id = (string) Str::uuid();

        $this->book([], ['X-Request-Id' => $id])->assertCreated()->assertHeader('X-Request-Id', $id);

        $this->assertSame($id, Reservation::first()->request_id);
    }

    public function test_creation_is_rate_limited_per_ip(): void
    {
        config(['booking.create_rate_limit' => 2]);

        $this->book(['party_size' => 1])->assertCreated();
        $this->book(['party_size' => 1])->assertCreated();
        $this->book(['party_size' => 1])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    // ── 策略切換（壓測用）──────────────────────────────────────────────

    public function test_strategy_header_is_ignored_unless_explicitly_enabled(): void
    {
        // 預設未開啟：就算帶了不存在的策略名稱也直接忽略，照設定檔的預設策略處理
        $this->book([], ['X-Booking-Strategy' => 'bogus'])->assertCreated();
    }

    public function test_strategy_header_switches_strategy_when_enabled_outside_production(): void
    {
        config(['booking.allow_strategy_header' => true]);

        $this->book([], ['X-Booking-Strategy' => 'pessimistic'])->assertCreated();
        $this->book([], ['X-Booking-Strategy' => 'unsafe'])->assertCreated();
        $this->book([], ['X-Booking-Strategy' => 'bogus'])->assertStatus(422)->assertJsonPath('code', 'INVALID_STRATEGY');
    }

    public function test_strategy_header_is_ignored_in_production_even_when_enabled(): void
    {
        config(['booking.allow_strategy_header' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        // 若標頭生效，'unsafe' 會被接受、'bogus' 會 422；正式環境兩者都必須被忽略
        $this->book([], ['X-Booking-Strategy' => 'bogus'])->assertCreated();
        $this->assertSame(2, $this->slot->fresh()->booked);
    }
}
