<?php

namespace Tests\Feature\Booking;

use App\Booking\ReservationService;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookableStore;
use Tests\TestCase;

class CancellationTest extends TestCase
{
    use CreatesBookableStore;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBookable(tables: 1);
    }

    private function makeReservation(int $partySize = 2): Reservation
    {
        return app(ReservationService::class)->create($this->bookingPayload(['party_size' => $partySize]));
    }

    private function cancel(Reservation $reservation, ?string $phone = null, ?string $code = null)
    {
        return $this->postJson('/api/reservations/cancel', [
            'code' => $code ?? $reservation->code,
            'phone' => $phone ?? $reservation->phone,
        ]);
    }

    public function test_cancelling_returns_the_seats_and_the_table(): void
    {
        $reservation = $this->makeReservation(3);
        $this->assertSame(3, $this->slot->fresh()->booked);
        $this->assertDatabaseCount('reservation_tables', 1);

        $this->cancel($reservation)->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('status_label', '已取消');

        $this->assertSame(0, $this->slot->fresh()->booked);
        $this->assertDatabaseCount('reservation_tables', 0);
    }

    public function test_released_table_and_seats_can_be_booked_again(): void
    {
        $first = $this->makeReservation(4);

        // 唯一的桌子被佔用
        $this->postJson('/api/reservations', $this->bookingPayload())->assertStatus(409)->assertJsonPath('code', 'NO_TABLE_AVAILABLE');

        $this->cancel($first)->assertOk();

        $this->postJson('/api/reservations', $this->bookingPayload())->assertCreated();
    }

    public function test_cancelling_twice_is_idempotent_and_does_not_release_seats_twice(): void
    {
        $this->store->diningTables()->create(['code' => 'T2', 'min_seats' => 1, 'max_seats' => 4]); // 需要第二張桌子給另一筆訂位
        $reservation = $this->makeReservation(2);
        $other = app(ReservationService::class)->create($this->bookingPayload(['party_size' => 1, 'phone' => '0922333444']));
        $this->assertSame(3, $this->slot->fresh()->booked);

        $this->cancel($reservation)->assertOk();
        $this->cancel($reservation)->assertOk()->assertJsonPath('status', 'cancelled');

        // 只歸還一次：剩下另一筆的 1 位，不是 -1 或錯亂的數字
        $this->assertSame(1, $this->slot->fresh()->booked);
        $this->assertSame(ReservationStatus::Confirmed, $other->fresh()->status);
    }

    public function test_pending_payment_reservation_can_be_cancelled(): void
    {
        $this->store->update(['deposit_per_person' => 100]);
        $reservation = $this->makeReservation(2);
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status);

        $this->cancel($reservation)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, $this->slot->fresh()->booked);
    }

    public function test_wrong_phone_or_code_looks_exactly_like_a_missing_reservation(): void
    {
        $reservation = $this->makeReservation();

        $wrongPhone = $this->cancel($reservation, phone: '0999999999');
        $wrongCode = $this->cancel($reservation, code: 'ZZZZZZZZ');

        $wrongPhone->assertStatus(404)->assertJsonPath('code', 'RESERVATION_NOT_FOUND');
        $wrongCode->assertStatus(404)->assertJsonPath('code', 'RESERVATION_NOT_FOUND');
        $this->assertSame($wrongPhone->json('message'), $wrongCode->json('message'));
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_code_is_case_insensitive(): void
    {
        $reservation = $this->makeReservation();

        $this->cancel($reservation, code: strtolower($reservation->code))->assertOk();
    }

    public function test_paid_reservation_cannot_be_cancelled_without_a_refund(): void
    {
        $reservation = $this->makeReservation();
        $reservation->update(['payment_status' => PaymentStatus::Paid]);

        $this->cancel($reservation)->assertStatus(409)->assertJsonPath('code', 'REFUND_REQUIRED');

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(2, $this->slot->fresh()->booked);
        $this->assertDatabaseCount('reservation_tables', 1);
    }

    public function test_arrived_reservation_cannot_be_cancelled(): void
    {
        $reservation = $this->makeReservation();
        $reservation->update(['status' => ReservationStatus::Arrived]);

        $this->cancel($reservation)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
        $this->assertSame(2, $this->slot->fresh()->booked);
    }

    public function test_lookup_returns_the_reservation_for_the_right_code_and_phone(): void
    {
        $reservation = $this->makeReservation();

        $this->postJson('/api/reservations/lookup', ['code' => $reservation->code, 'phone' => $reservation->phone])
            ->assertOk()
            ->assertJsonPath('code', $reservation->code)
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('store.name', $this->store->name);

        $this->postJson('/api/reservations/lookup', ['code' => $reservation->code, 'phone' => '0999999999'])->assertStatus(404);
    }

    public function test_lookup_validates_input_format(): void
    {
        $this->postJson('/api/reservations/lookup', [])->assertStatus(422)->assertJsonValidationErrors('code', 'errors');
        // 寬鬆模式下手機號碼不可省略（嚴格模式由憑證決定，見 Sms/PhoneVerificationTest）
        $this->postJson('/api/reservations/lookup', ['code' => 'ABCDEFGH'])->assertStatus(422)->assertJsonPath('code', 'PHONE_REQUIRED');
        $this->postJson('/api/reservations/lookup', ['code' => 'ABCDEFGH', 'phone' => '123'])->assertStatus(422)->assertJsonValidationErrors('phone', 'errors');
        $this->postJson('/api/reservations/lookup', ['code' => 'short', 'phone' => '0912345678'])->assertStatus(422)->assertJsonValidationErrors('code', 'errors');
    }

    public function test_lookup_is_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/reservations/lookup', ['code' => 'ABCDEFGH', 'phone' => '0912345678'])->assertStatus(404);
        }

        $this->postJson('/api/reservations/lookup', ['code' => 'ABCDEFGH', 'phone' => '0912345678'])
            ->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
