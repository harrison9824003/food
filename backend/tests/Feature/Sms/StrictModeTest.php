<?php

namespace Tests\Feature\Sms;

use App\Models\Reservation;
use App\Sms\PhoneToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPayments;
use Tests\TestCase;

/**
 * 嚴格模式（正式環境一律如此）：訂位、查詢、取消、付款都必須帶簡訊驗證憑證，
 * 手機號碼由憑證決定，請求內容裡的手機號碼不採信。
 */
class StrictModeTest extends TestCase
{
    use CreatesPayments;
    use RefreshDatabase;

    private const PHONE = '0912345678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments();
        config(['sms.require_verification' => true]);
    }

    /** @return array<string, string> */
    private function as(string $phone = self::PHONE): array
    {
        return ['X-Phone-Token' => PhoneToken::issue($phone)['token']];
    }

    private function book(array $headers = [], array $overrides = [])
    {
        return $this->withHeaders($headers)->postJson('/api/reservations', $this->bookingPayload($overrides));
    }

    // ── 訂位 ────────────────────────────────────────────────────────────

    public function test_booking_requires_a_verified_phone(): void
    {
        $this->book()->assertStatus(401)->assertJsonPath('code', 'PHONE_NOT_VERIFIED');

        $this->assertDatabaseCount('reservations', 0);
        $this->assertSame(0, $this->slot->fresh()->booked, '沒驗證手機就不可佔名額');
    }

    public function test_booking_with_a_valid_token_records_the_verification_time(): void
    {
        $this->book($this->as())->assertCreated();

        $this->assertEquals(now(), Reservation::firstOrFail()->phone_verified_at);
    }

    public function test_a_token_for_one_phone_cannot_book_in_anothers_name(): void
    {
        $this->book($this->as('0922222222'))->assertStatus(403)->assertJsonPath('code', 'PHONE_MISMATCH');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_garbage_and_expired_tokens_are_refused(): void
    {
        $this->book(['X-Phone-Token' => 'garbage'])->assertStatus(401);

        $headers = $this->as();
        Carbon::setTestNow(now()->addMinutes(16));
        $this->book($headers)->assertStatus(401)->assertJsonPath('code', 'PHONE_NOT_VERIFIED');
    }

    public function test_production_is_always_strict_even_if_the_switch_is_off(): void
    {
        config(['sms.require_verification' => false]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->book()->assertStatus(401)->assertJsonPath('code', 'PHONE_NOT_VERIFIED');
    }

    // ── 查詢、取消、付款 ────────────────────────────────────────────────

    public function test_lookup_cancel_and_pay_need_the_token_and_ignore_the_phone_in_the_body(): void
    {
        $reservation = $this->makeReservation();
        $body = ['code' => $reservation->code, 'phone' => self::PHONE];

        foreach (['lookup', 'cancel', 'pay'] as $action) {
            // 光知道代號與手機號碼不夠：沒有憑證一律擋下（手機號碼不是祕密）
            $this->postJson("/api/reservations/{$action}", $body)->assertStatus(401)->assertJsonPath('code', 'PHONE_NOT_VERIFIED');
        }

        $this->withHeaders($this->as())->postJson('/api/reservations/lookup', ['code' => $reservation->code])
            ->assertOk()->assertJsonPath('code', $reservation->code);
    }

    public function test_a_token_for_another_phone_cannot_see_or_touch_the_reservation(): void
    {
        $reservation = $this->makeReservation();
        $other = $this->as('0922222222');

        // 就算請求內容謊稱是 0912345678，也以憑證的手機為準，所以查不到
        foreach (['lookup', 'cancel', 'pay'] as $action) {
            $this->withHeaders($other)->postJson("/api/reservations/{$action}", ['code' => $reservation->code, 'phone' => self::PHONE])
                ->assertStatus(404)->assertJsonPath('code', 'RESERVATION_NOT_FOUND');
        }
        $this->assertSame(2, $this->slot->fresh()->booked);
    }

    public function test_the_owner_can_cancel_and_pay_with_a_token(): void
    {
        $reservation = $this->makeReservation();

        $this->withHeaders($this->as())->postJson('/api/reservations/pay', ['code' => $reservation->code])
            ->assertOk()->assertJsonStructure(['action', 'fields', 'merchant_trade_no']);

        $this->withHeaders($this->as())->postJson('/api/reservations/cancel', ['code' => $reservation->code])
            ->assertOk()->assertJsonPath('status', 'cancelled');
    }

    // ── 我的訂位（不必記得代號）────────────────────────────────────────

    public function test_my_reservations_lists_everything_booked_with_this_phone(): void
    {
        $this->store->update(['deposit_per_person' => 0]);
        $this->makeReservation(['party_size' => 1]);
        $this->makeReservation(['party_size' => 2]);
        $this->makeReservation(['phone' => '0922222222']); // 別人的

        // 非分頁的集合，回應根部直接是陣列（全域 withoutWrapping，和門市列表一致）
        $response = $this->withHeaders($this->as())->getJson('/api/my/reservations')->assertOk()->assertJsonCount(2);

        $this->assertEqualsCanonicalizing([1, 2], collect($response->json())->pluck('party_size')->all());
    }

    public function test_my_reservations_requires_a_token_and_never_trusts_a_phone_in_the_query(): void
    {
        $this->makeReservation();

        $this->getJson('/api/my/reservations?phone='.self::PHONE)->assertStatus(401);
        $this->withHeaders($this->as('0922222222'))->getJson('/api/my/reservations?phone='.self::PHONE)
            ->assertOk()->assertJsonCount(0);
    }

    public function test_my_reservations_is_newest_first_and_includes_cancelled_ones(): void
    {
        $this->store->update(['deposit_per_person' => 0]);
        $early = $this->makeSlot($this->store, '2026-10-06', '11:00:00');
        $this->makeReservation(['slot_id' => $early->id]);
        $cancelled = $this->makeReservation();
        $this->withHeaders($this->as())->postJson('/api/reservations/cancel', ['code' => $cancelled->code])->assertOk();

        $data = $this->withHeaders($this->as())->getJson('/api/my/reservations')->assertOk()->json();

        $this->assertSame(['12:00', '11:00'], array_column($data, 'time'));
        $this->assertContains('cancelled', array_column($data, 'status'));
    }
}
