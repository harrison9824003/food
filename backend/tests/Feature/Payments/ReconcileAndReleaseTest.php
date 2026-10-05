<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Payments\PaymentGateway;
use App\Payments\PaymentGatewayException;
use App\Payments\PaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesPayments;
use Tests\TestCase;

/**
 * 對帳補償（payments:reconcile）與逾時釋放（reservations:release-expired）。
 * 時間：訂位與付款建立於 10:00，保留到 10:10，寬限 2 分鐘 → 10:12 之後才會被釋放。
 */
class ReconcileAndReleaseTest extends TestCase
{
    use CreatesPayments;
    use RefreshDatabase;

    private const QUERY_URL = 'https://payment-stage.ecpay.com.tw/Cashier/QueryTradeInfo/V5';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow("2026-10-05 {$time}");
    }

    // ── 對帳 ────────────────────────────────────────────────────────────

    public function test_reconcile_confirms_a_payment_whose_notification_never_arrived(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        Http::fake([self::QUERY_URL => Http::response($this->ecpayQueryResponse($payment, paid: true))]);

        $this->at('10:04');
        $this->artisan('payments:reconcile')->assertSuccessful();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('reconcile', $payment->confirmed_via);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        Http::assertSent(fn ($request) => $request['MerchantTradeNo'] === $payment->merchant_trade_no && isset($request['CheckMacValue']));
    }

    public function test_reconcile_skips_payments_that_are_still_young(): void
    {
        $payment = $this->startPayment($this->makeReservation());
        Http::fake();

        $this->at('10:02'); // 才建立 2 分鐘，使用者可能還在綠界頁面上
        $this->artisan('payments:reconcile')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_reconcile_leaves_an_unpaid_payment_pending(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        Http::fake([self::QUERY_URL => Http::response($this->ecpayQueryResponse($payment, paid: false))]);

        $this->at('10:05');
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
    }

    public function test_reconcile_never_trusts_a_response_with_a_bad_signature(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        // 中間有人把「未付款」改成「已付款」，但沒辦法重新簽章
        Http::fake([self::QUERY_URL => Http::response($this->ecpayQueryResponse($payment, paid: true, sign: false).'&CheckMacValue='.str_repeat('B', 64))]);

        $this->at('10:05');
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
    }

    public function test_reconcile_treats_an_unreachable_gateway_as_unknown_not_unpaid(): void
    {
        $payment = $this->startPayment($this->makeReservation());
        Http::fake([self::QUERY_URL => Http::response('boom', 500)]);

        $this->at('10:05');

        $this->assertSame(PaymentReconciler::UNKNOWN, app(PaymentReconciler::class)->reconcile($payment));
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_reconcile_rejects_a_paid_response_whose_amount_differs_from_our_record(): void
    {
        $reservation = $this->makeReservation(['party_size' => 2]);
        $payment = $this->startPayment($reservation);
        Http::fake([self::QUERY_URL => Http::response($this->ecpayQueryResponse($payment, paid: true, overrides: ['TradeAmt' => '1']))]);

        $this->at('10:05');

        $this->assertSame(PaymentReconciler::UNKNOWN, app(PaymentReconciler::class)->reconcile($payment));
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
    }

    public function test_reconcile_and_notification_together_process_the_payment_only_once(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        Http::fake([self::QUERY_URL => Http::response($this->ecpayQueryResponse($payment, paid: true))]);

        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false);
        $this->at('10:05');
        $this->artisan('payments:reconcile')->assertSuccessful(); // 通知已處理過，對帳不會再撿它（狀態已非 pending）

        // 直接再用對帳邏輯處理一次，也必須是冪等的
        app(PaymentReconciler::class)->reconcile($payment->fresh());

        $this->assertSame('notify', $payment->fresh()->confirmed_via);
        $this->assertSame(2, $this->slot->fresh()->booked);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_reconcile_abandons_payments_that_stay_unpaid_for_too_long(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        Http::fake([self::QUERY_URL => Http::response($this->ecpayQueryResponse($payment, paid: false))]);

        $this->at('12:01'); // 超過 120 分鐘
        $this->artisan('payments:reconcile')->assertSuccessful();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('abandoned', $payment->failure_reason);
    }

    // ── 逾時釋放 ────────────────────────────────────────────────────────

    public function test_an_expired_hold_is_released_after_the_grace_period(): void
    {
        $reservation = $this->makeReservation(['party_size' => 3]);
        Http::fake();

        $this->at('10:13');
        $this->artisan('reservations:release-expired')->assertSuccessful();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(0, $this->slot->fresh()->booked, '名額歸還');
        $this->assertDatabaseCount('reservation_tables', 0);
    }

    public function test_a_released_slot_can_be_booked_again(): void
    {
        $this->store->diningTables()->where('code', '!=', 'T1')->delete(); // 只剩一張桌子
        $this->makeReservation();
        $this->postJson('/api/reservations', $this->bookingPayload(['phone' => '0922111222']))->assertStatus(409);

        $this->at('10:13');
        $this->artisan('reservations:release-expired')->assertSuccessful();

        $this->postJson('/api/reservations', $this->bookingPayload(['phone' => '0922111222']))->assertCreated();
    }

    public function test_a_hold_is_kept_during_the_grace_period_and_before_expiry(): void
    {
        $reservation = $this->makeReservation();
        Http::fake();

        foreach (['10:09', '10:11'] as $time) {
            $this->at($time);
            $this->artisan('reservations:release-expired')->assertSuccessful();
            $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status, "{$time} 還不該釋放");
        }
    }

    public function test_paid_and_free_reservations_are_never_released(): void
    {
        $paid = $this->makeReservation(['phone' => '0911000001']);
        $this->sendNotify($this->notifyPayload($this->startPayment($paid)))->assertSeeText('1|OK', false);

        $this->store->update(['deposit_per_person' => 0]);
        $free = $this->makeReservation(['phone' => '0911000002']);
        Http::fake();

        $this->at('11:00');
        $this->artisan('reservations:release-expired')->assertSuccessful();

        $this->assertSame(ReservationStatus::Confirmed, $paid->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $free->fresh()->status);
    }

    public function test_release_reconciles_first_and_keeps_a_reservation_that_was_actually_paid(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        // 顧客其實付了，但綠界的背景通知漏接了
        Http::fake([self::QUERY_URL => Http::response($this->ecpayQueryResponse($payment, paid: true))]);

        $this->at('10:13');
        $this->artisan('reservations:release-expired')->assertSuccessful();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status, '不可把已付款的人的座位放掉');
        $this->assertSame(2, $this->slot->fresh()->booked);
    }

    public function test_release_waits_while_the_gateway_is_unreachable_then_gives_up(): void
    {
        $reservation = $this->makeReservation();
        $this->startPayment($reservation);
        Http::fake([self::QUERY_URL => Http::response('down', 503)]);

        // 綠界故障、無法確認有沒有付款：先不放，下一輪再試
        $this->at('10:15');
        $this->artisan('reservations:release-expired')->assertSuccessful();
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);

        // 等太久了（保留到期後 30 分鐘）：不能讓綠界故障把名額永遠占住，照樣釋放
        $this->at('10:41');
        $this->artisan('reservations:release-expired')->assertSuccessful();
        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    public function test_pending_payments_stay_pending_after_release_so_a_late_payment_can_still_be_found(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        // 第一次查詢（釋放前的對帳）：還沒付；第二次（之後的對帳排程）：其實付了
        Http::fake([self::QUERY_URL => Http::sequence()
            ->push($this->ecpayQueryResponse($payment, paid: false))
            ->push($this->ecpayQueryResponse($payment, paid: true))]);

        $this->at('10:13');
        $this->artisan('reservations:release-expired')->assertSuccessful();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);

        // 之後對帳發現其實付了 → 走晚到付款的補救流程（名額還在，所以補回）
        $this->at('10:20');
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_gateway_exceptions_are_typed(): void
    {
        Http::fake([self::QUERY_URL => Http::response('x', 500)]);

        $this->expectException(PaymentGatewayException::class);
        app(PaymentGateway::class)->queryTrade('ANYTRADE');
    }
}
