<?php

namespace Tests\Feature\Booking;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesBookableStore;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use CreatesBookableStore;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBookable();
    }

    private function availability(int $partySize = 2, string $date = '2026-10-06')
    {
        return $this->getJson("/api/stores/{$this->store->id}/availability?date={$date}&party_size={$partySize}");
    }

    public function test_lists_slots_with_remaining_seats_and_bookable_flag(): void
    {
        $this->makeSlot($this->store, '2026-10-06', '18:00:00', 10, 4);

        $this->availability()->assertOk()
            ->assertJsonPath('slots.0.start_time', '12:00')
            ->assertJsonPath('slots.0.remaining', 20)
            ->assertJsonPath('slots.0.bookable', true)
            ->assertJsonPath('slots.0.reason', null)
            ->assertJsonPath('slots.1.start_time', '18:00')
            ->assertJsonPath('slots.1.remaining', 6);
    }

    public function test_full_slot_is_not_bookable(): void
    {
        $this->slot->update(['booked' => 20]);

        $this->availability()->assertOk()
            ->assertJsonPath('slots.0.bookable', false)
            ->assertJsonPath('slots.0.reason', 'SLOT_FULL');
    }

    public function test_slot_with_too_few_seats_left_for_the_party_is_not_bookable(): void
    {
        $this->slot->update(['booked' => 19]); // 只剩 1 位

        $this->availability(1)->assertJsonPath('slots.0.bookable', true);
        $this->availability(2)->assertJsonPath('slots.0.bookable', false)->assertJsonPath('slots.0.reason', 'SLOT_FULL');
    }

    public function test_slot_is_not_bookable_when_no_table_can_seat_the_party(): void
    {
        // 名額夠，但桌子最大只有 4 人桌且不可併桌
        $this->availability(6)->assertOk()
            ->assertJsonPath('slots.0.remaining', 20)
            ->assertJsonPath('slots.0.bookable', false)
            ->assertJsonPath('slots.0.reason', 'NO_TABLE_AVAILABLE');
    }

    public function test_closed_date_makes_every_slot_unbookable(): void
    {
        $this->store->closedDates()->create(['closed_date' => '2026-10-06']);

        $this->availability()->assertJsonPath('slots.0.bookable', false)->assertJsonPath('slots.0.reason', 'DATE_CLOSED');
    }

    public function test_slots_past_the_cutoff_are_not_bookable(): void
    {
        Carbon::setTestNow('2026-10-06 11:30:00');

        $this->availability()->assertJsonPath('slots.0.bookable', false)->assertJsonPath('slots.0.reason', 'CUTOFF_PASSED');
    }

    public function test_date_without_slots_returns_an_empty_list(): void
    {
        $this->availability(2, '2026-10-07')->assertOk()->assertJsonCount(0, 'slots');
    }

    public function test_inactive_store_is_not_found(): void
    {
        $this->store->update(['is_active' => false]);

        $this->availability()->assertStatus(404);
    }

    public function test_availability_validates_input(): void
    {
        $id = $this->store->id;

        $this->getJson("/api/stores/{$id}/availability")->assertStatus(422)->assertJsonValidationErrors(['date', 'party_size'], 'errors');
        $this->getJson("/api/stores/{$id}/availability?date=10/06&party_size=2")->assertStatus(422)->assertJsonValidationErrors('date', 'errors');
        $this->getJson("/api/stores/{$id}/availability?date=2026-10-06&party_size=99")->assertStatus(422)->assertJsonValidationErrors('party_size', 'errors');
    }

    public function test_store_list_only_contains_active_stores(): void
    {
        Store::factory()->create(['is_active' => false]);

        $this->getJson('/api/stores')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->store->id);
    }

    public function test_calendar_marks_weekly_and_specific_closed_days(): void
    {
        $this->store->update(['advance_days' => 3]);
        $this->openAllWeek($this->store);
        $this->store->businessHours()->where('weekday', 2)->update(['is_closed' => true]); // 週二公休（10/06）
        $this->store->closedDates()->create(['closed_date' => '2026-10-08']);             // 週四特別公休

        $response = $this->getJson("/api/stores/{$this->store->id}/calendar")->assertOk()
            ->assertJsonPath('from', '2026-10-05')
            ->assertJsonPath('to', '2026-10-08')
            ->assertJsonCount(4, 'dates');

        $this->assertSame(
            ['2026-10-05' => true, '2026-10-06' => false, '2026-10-07' => true, '2026-10-08' => false],
            collect($response->json('dates'))->pluck('open', 'date')->all(),
        );
    }
}
