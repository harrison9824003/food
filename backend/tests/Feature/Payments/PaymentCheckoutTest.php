<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Payments\CheckMacValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPayments;
use Tests\TestCase;

class PaymentCheckoutTest extends TestCase
{
    use CreatesPayments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments();
    }

    private function pay(string $code, string $phone = '0912345678')
    {
        return $this->postJson('/api/reservations/pay', ['code' => $code, 'phone' => $phone]);
    }

    public function test_returns_a_signed_form_for_ecpay(): void
    {
        $reservation = $this->makeReservation(['party_size' => 3]);

        $response = $this->pay($reservation->code)->assertOk()
            ->assertJsonPath('action', 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5')
            ->assertJsonPath('fields.MerchantID', '2000132')
            ->assertJsonPath('fields.TotalAmount', '600')
            ->assertJsonPath('fields.ChoosePayment', 'Credit')
            ->assertJsonPath('fields.ReturnURL', config('ecpay.notify_url'))
            ->assertJsonPath('fields.OrderResultURL', config('ecpay.order_result_url'));

        $fields = $response->json('fields');
        $this->assertTrue(CheckMacValue::verify($fields, config('ecpay.hash_key'), config('ecpay.hash_iv')), '給綠界的表單簽章必須有效');
        $this->assertSame($fields['MerchantTradeNo'], $response->json('merchant_trade_no'));
        $this->assertStringContainsString("/payment/return?code={$reservation->code}", $fields['ClientBackURL']);
    }

    public function test_creates_a_pending_payment_with_the_deposit_amount(): void
    {
        $reservation = $this->makeReservation(['party_size' => 2]);

        $this->pay($reservation->code)->assertOk();

        $payment = Payment::firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(400, $payment->amount);
        $this->assertSame($reservation->id, $payment->reservation_id);
        $this->assertSame(PaymentStatus::Pending, $reservation->fresh()->payment_status);
    }

    public function test_trade_number_fits_ecpay_limits_and_is_new_for_every_attempt(): void
    {
        $reservation = $this->makeReservation();

        $first = $this->pay($reservation->code)->json('merchant_trade_no');
        $second = $this->pay($reservation->code)->json('merchant_trade_no');

        $this->assertNotSame($first, $second, '綠界不接受重複的商店交易編號，重試必須用新編號');
        foreach ([$first, $second] as $no) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{1,20}$/', $no);
        }
        $this->assertSame(2, Payment::count());
    }

    public function test_requires_the_matching_phone_number(): void
    {
        $reservation = $this->makeReservation();

        $this->pay($reservation->code, '0999999999')->assertStatus(404)->assertJsonPath('code', 'RESERVATION_NOT_FOUND');
        $this->assertSame(0, Payment::count());
    }

    public function test_a_reservation_that_needs_no_deposit_cannot_be_paid(): void
    {
        $this->store->update(['deposit_per_person' => 0]);
        $reservation = $this->makeReservation();

        $this->pay($reservation->code)->assertStatus(409)->assertJsonPath('code', 'NOT_PAYABLE');
    }

    public function test_an_expired_hold_cannot_be_paid(): void
    {
        $reservation = $this->makeReservation();

        Carbon::setTestNow('2026-10-05 10:10:01'); // 保留到 10:10 為止

        $this->pay($reservation->code)->assertStatus(409)->assertJsonPath('code', 'HOLD_EXPIRED');
        $this->assertSame(0, Payment::count());
    }

    public function test_a_cancelled_reservation_cannot_be_paid(): void
    {
        $reservation = $this->makeReservation();
        $this->postJson('/api/reservations/cancel', ['code' => $reservation->code, 'phone' => $reservation->phone])->assertOk();

        $this->pay($reservation->code)->assertStatus(409)->assertJsonPath('code', 'NOT_PAYABLE');
    }

    public function test_reservation_response_tells_the_client_whether_it_can_be_paid(): void
    {
        $reservation = $this->makeReservation();
        $lookup = fn () => $this->postJson('/api/reservations/lookup', ['code' => $reservation->code, 'phone' => $reservation->phone]);

        $lookup()->assertJsonPath('can_pay', true)->assertJsonPath('server_time', now()->toIso8601String());

        Carbon::setTestNow('2026-10-05 10:11:00');
        $lookup()->assertJsonPath('can_pay', false);
    }
}
