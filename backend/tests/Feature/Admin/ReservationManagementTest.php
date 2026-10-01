<?php

namespace Tests\Feature\Admin;

use App\Booking\ReservationService;
use App\Enums\ReservationStatus;
use App\Models\DiningTable;
use App\Models\Reservation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesBookableStore;
use Tests\TestCase;

class ReservationManagementTest extends TestCase
{
    use CreatesBookableStore;
    use RefreshDatabase;

    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBookable();

        $this->otherStore = Store::factory()->create(['advance_days' => 14, 'cutoff_minutes' => 60]);
        DiningTable::create(['store_id' => $this->otherStore->id, 'code' => 'X1', 'min_seats' => 1, 'max_seats' => 4]);
    }

    private function reserve(array $overrides = [], ?Store $store = null, string $date = '2026-10-06'): Reservation
    {
        $slot = $store
            ? $this->makeSlot($store, $date, '12:00:00')
            : ($date === '2026-10-06' ? $this->slot : $this->makeSlot($this->store, $date, '12:00:00'));

        return app(ReservationService::class)->create($this->bookingPayload(['slot_id' => $slot->id] + $overrides));
    }

    private function actingAsUser(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    // ── 列表範圍與搜尋 ──────────────────────────────────────────────────

    public function test_hq_sees_every_store_and_can_filter(): void
    {
        $this->reserve();
        $this->reserve(store: $this->otherStore);
        $this->actingAsUser(User::factory()->hqAdmin()->create());

        $this->getJson('/api/admin/reservations')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/admin/reservations?store_id={$this->otherStore->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_store_users_only_see_their_own_store_even_with_a_store_id_filter(): void
    {
        $this->reserve();
        $this->reserve(store: $this->otherStore);

        foreach ([User::factory()->storeManager($this->store)->create(), User::factory()->staff($this->store)->create()] as $user) {
            $this->actingAsUser($user);

            $this->getJson('/api/admin/reservations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.store.id', $this->store->id);
            $this->getJson("/api/admin/reservations?store_id={$this->otherStore->id}")->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.store.id', $this->store->id);
        }
    }

    public function test_list_filters_by_date_status_and_search_term(): void
    {
        $alice = $this->reserve(['customer_name' => 'Alice', 'phone' => '0911111111']);
        $this->reserve(['customer_name' => 'Bob', 'phone' => '0922222222'], date: '2026-10-07');
        $this->actingAsUser(User::factory()->storeManager($this->store)->create());

        $this->getJson('/api/admin/reservations?date=2026-10-07')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_name', 'Bob');
        $this->getJson('/api/admin/reservations?q=Alic')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $alice->code);
        $this->getJson('/api/admin/reservations?q=0922')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_name', 'Bob');
        $this->getJson('/api/admin/reservations?q='.$alice->code)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/reservations?status=cancelled')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/reservations?status=confirmed')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/reservations?status=bogus')->assertStatus(422);
    }

    public function test_list_shows_contact_details_tables_and_allowed_actions(): void
    {
        $this->reserve(['email' => 'a@example.com', 'note' => '靠窗']);
        $this->actingAsUser(User::factory()->staff($this->store)->create());

        $this->getJson('/api/admin/reservations')->assertOk()
            ->assertJsonPath('data.0.phone', '0912345678')
            ->assertJsonPath('data.0.email', 'a@example.com')
            ->assertJsonPath('data.0.note', '靠窗')
            ->assertJsonPath('data.0.date', '2026-10-06')
            ->assertJsonPath('data.0.time', '12:00')
            ->assertJsonPath('data.0.tables.0', 'T1')
            ->assertJsonPath('data.0.allowed_statuses', ['arrived', 'no_show', 'cancelled']);
    }

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/admin/reservations')->assertStatus(401);
    }

    // ── 狀態變更 ────────────────────────────────────────────────────────

    public function test_staff_can_mark_a_reservation_as_arrived(): void
    {
        $reservation = $this->reserve();
        $this->actingAsUser(User::factory()->staff($this->store)->create());

        $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['status' => 'arrived'])
            ->assertOk()->assertJsonPath('status', 'arrived')->assertJsonPath('allowed_statuses', []);

        // 已到的客人仍佔用名額與桌位
        $this->assertSame(2, $this->slot->fresh()->booked);
        $this->assertDatabaseCount('reservation_tables', 1);
    }

    public function test_marking_no_show_or_cancelling_releases_seats_and_table(): void
    {
        $this->actingAsUser(User::factory()->storeManager($this->store)->create());

        foreach (['no_show', 'cancelled'] as $status) {
            $reservation = $this->reserve();
            $this->assertSame(2, $this->slot->fresh()->booked);

            $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['status' => $status])->assertOk()->assertJsonPath('status', $status);

            $this->assertSame(0, $this->slot->fresh()->booked, "{$status} 應歸還名額");
            $this->assertDatabaseCount('reservation_tables', 0);
        }
    }

    public function test_status_transitions_are_validated(): void
    {
        $reservation = $this->reserve();
        $this->actingAsUser(User::factory()->hqAdmin()->create());
        $url = "/api/admin/reservations/{$reservation->id}/status";

        $this->patchJson($url, ['status' => 'arrived'])->assertOk();
        // 已到之後不能再取消或改成未到
        $this->patchJson($url, ['status' => 'cancelled'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
        $this->patchJson($url, ['status' => 'no_show'])->assertStatus(409);
        // 重複送出同一個狀態是冪等的
        $this->patchJson($url, ['status' => 'arrived'])->assertOk();
    }

    public function test_only_manual_statuses_can_be_requested(): void
    {
        $reservation = $this->reserve();
        $this->actingAsUser(User::factory()->hqAdmin()->create());

        foreach (['confirmed', 'pending_payment', 'expired', 'nonsense', ''] as $status) {
            $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['status' => $status])->assertStatus(422);
        }
    }

    public function test_users_cannot_change_other_stores_reservations(): void
    {
        $foreign = $this->reserve(store: $this->otherStore);

        foreach ([User::factory()->storeManager($this->store)->create(), User::factory()->staff($this->store)->create()] as $user) {
            $this->actingAsUser($user);

            $this->patchJson("/api/admin/reservations/{$foreign->id}/status", ['status' => 'cancelled'])
                ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        }

        $this->assertSame(ReservationStatus::Confirmed, $foreign->fresh()->status);
    }

    public function test_hq_can_change_any_stores_reservation(): void
    {
        $foreign = $this->reserve(store: $this->otherStore);
        $this->actingAsUser(User::factory()->hqAdmin()->create());

        $this->patchJson("/api/admin/reservations/{$foreign->id}/status", ['status' => 'arrived'])->assertOk();
    }
}
