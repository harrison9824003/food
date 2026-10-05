<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesPayments;
use Tests\TestCase;

class ReturnAndSimulatorTest extends TestCase
{
    use CreatesPayments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments();
        config(['ecpay.frontend_url' => 'http://frontend.test']);
    }

    // ── 瀏覽器導回（只顯示，不改狀態）──────────────────────────────────

    public function test_browser_return_redirects_to_the_frontend_result_page(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);

        $this->post('/api/payments/ecpay/return', ['MerchantTradeNo' => $payment->merchant_trade_no, 'RtnCode' => '1'])
            ->assertRedirect("http://frontend.test/payment/return?code={$reservation->code}");
    }

    public function test_browser_return_never_changes_any_state(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);

        // 即使導回的請求「聲稱」付款成功，也不能因此確認訂位：狀態只認背景通知
        $this->post('/api/payments/ecpay/return', ['MerchantTradeNo' => $payment->merchant_trade_no, 'RtnCode' => '1', 'TradeAmt' => '400']);
        $this->get('/api/payments/ecpay/return?MerchantTradeNo='.$payment->merchant_trade_no.'&RtnCode=1');

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
    }

    public function test_browser_return_with_an_unknown_trade_goes_to_the_lookup_page_and_cannot_redirect_elsewhere(): void
    {
        $this->post('/api/payments/ecpay/return', ['MerchantTradeNo' => 'NOPE', 'redirect' => 'https://evil.example'])
            ->assertRedirect('http://frontend.test/lookup');
        $this->post('/api/payments/ecpay/return', [])->assertRedirect('http://frontend.test/lookup');
    }

    // ── 本機付款模擬器 ──────────────────────────────────────────────────

    private function simulate(array $data)
    {
        return $this->postJson('/api/dev/ecpay/simulate', $data);
    }

    public function test_simulator_is_unavailable_unless_explicitly_enabled(): void
    {
        $payment = $this->startPayment($this->makeReservation());
        Http::fake();

        config(['ecpay.simulator_enabled' => false]);
        $this->simulate(['merchant_trade_no' => $payment->merchant_trade_no, 'outcome' => 'paid'])->assertStatus(404);

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_simulator_is_unavailable_in_production_even_when_enabled(): void
    {
        $payment = $this->startPayment($this->makeReservation());
        Http::fake();
        config(['ecpay.simulator_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->simulate(['merchant_trade_no' => $payment->merchant_trade_no, 'outcome' => 'paid'])->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_simulator_posts_a_correctly_signed_notification_to_our_notify_url(): void
    {
        $payment = $this->startPayment($this->makeReservation(['party_size' => 3]));
        config(['ecpay.simulator_enabled' => true]);
        Http::fake(['http://nginx/*' => Http::response('1|OK')]);

        $this->simulate(['merchant_trade_no' => $payment->merchant_trade_no, 'outcome' => 'paid'])
            ->assertOk()->assertJsonPath('results.0.response', '1|OK');

        Http::assertSent(function ($request) use ($payment) {
            return $request->url() === 'http://nginx/api/payments/ecpay/notify'
                && $request->hasHeader('X-Payment-Simulator')
                && $request['MerchantTradeNo'] === $payment->merchant_trade_no
                && $request['TradeAmt'] === '600'
                && $request['RtnCode'] === '1';
        });

        // 模擬器送出的內容，必須能通過和真實通知完全相同的驗章與處理流程
        $sent = collect(Http::recorded())->first()[0]->data();
        $this->sendNotify($sent)->assertSeeText('1|OK', false);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_simulator_can_replay_the_same_notification_to_demonstrate_idempotency(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        config(['ecpay.simulator_enabled' => true]);
        Http::fake(['http://nginx/*' => Http::response('1|OK')]);

        $this->simulate(['merchant_trade_no' => $payment->merchant_trade_no, 'outcome' => 'paid', 'times' => 3])
            ->assertOk()->assertJsonCount(3, 'results');

        Http::assertSentCount(3);
        $payloads = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data());
        $this->assertCount(1, $payloads->unique(), '重送的內容要完全相同，和真實的重送一致');
    }

    public function test_simulator_can_simulate_a_failed_payment(): void
    {
        $payment = $this->startPayment($this->makeReservation());
        config(['ecpay.simulator_enabled' => true]);
        Http::fake(['http://nginx/*' => Http::response('1|OK')]);

        $this->simulate(['merchant_trade_no' => $payment->merchant_trade_no, 'outcome' => 'failed'])->assertOk();

        Http::assertSent(fn ($request) => $request['RtnCode'] !== '1');
    }

    public function test_simulator_validates_input_and_unknown_payments(): void
    {
        config(['ecpay.simulator_enabled' => true]);

        $this->simulate([])->assertStatus(422);
        $this->simulate(['merchant_trade_no' => 'X', 'outcome' => 'maybe'])->assertStatus(422);
        $this->simulate(['merchant_trade_no' => 'X', 'outcome' => 'paid', 'times' => 9])->assertStatus(422);
        $this->simulate(['merchant_trade_no' => 'NOSUCHPAYMENT', 'outcome' => 'paid'])->assertStatus(404)->assertJsonPath('code', 'PAYMENT_NOT_FOUND');
    }
}
