<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPayments;
use Tests\TestCase;

class EcpayNotifyTest extends TestCase
{
    use CreatesPayments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments();
    }

    // ── 付款成功 ────────────────────────────────────────────────────────

    public function test_a_valid_success_notification_confirms_the_reservation(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);

        $this->sendNotify($this->notifyPayload($payment))->assertOk()->assertSeeText('1|OK', false);

        $payment->refresh();
        $reservation->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('notify', $payment->confirmed_via);
        $this->assertSame('2610021530'.str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT), $payment->ecpay_trade_no);
        $this->assertNotNull($payment->paid_at);
        $this->assertFalse($payment->needs_refund);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(PaymentStatus::Paid, $reservation->payment_status);
        $this->assertNull($reservation->hold_expires_at, '付款後不再有保留期限');
    }

    public function test_confirming_payment_keeps_the_seats_and_table_already_held(): void
    {
        $reservation = $this->makeReservation(['party_size' => 3]);
        $payment = $this->startPayment($reservation);

        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false);

        $this->assertSame(3, $this->slot->fresh()->booked, '名額在訂位時就扣了，付款成功不可再扣一次');
        $this->assertDatabaseCount('reservation_tables', 1);
    }

    public function test_the_response_is_plain_text(): void
    {
        $payment = $this->startPayment($this->makeReservation());

        $this->sendNotify($this->notifyPayload($payment))->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    // ── 驗章與資料核對 ──────────────────────────────────────────────────

    public function test_a_notification_with_a_bad_signature_is_rejected_and_changes_nothing(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);

        $forged = $this->notifyPayload($payment);
        $forged['CheckMacValue'] = str_repeat('A', 64);

        $this->sendNotify($forged)->assertOk()->assertSeeText('0|CheckMacValue Error', false);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
    }

    public function test_changing_any_field_after_signing_invalidates_the_signature(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);

        $tampered = $this->notifyPayload($payment);
        $tampered['RtnCode'] = '1'; // 例如攻擊者把失敗的通知改成成功
        $tampered['TradeAmt'] = '1';

        $this->sendNotify($tampered)->assertSeeText('0|CheckMacValue Error', false);
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
    }

    public function test_a_notification_without_a_signature_is_rejected(): void
    {
        $payment = $this->startPayment($this->makeReservation());

        $unsigned = $this->notifyPayload($payment);
        unset($unsigned['CheckMacValue']);

        $this->sendNotify($unsigned)->assertSeeText('0|CheckMacValue Error', false);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_an_amount_that_differs_from_our_record_is_rejected_even_if_correctly_signed(): void
    {
        $reservation = $this->makeReservation(['party_size' => 2]); // 應付 $400
        $payment = $this->startPayment($reservation);

        $this->sendNotify($this->notifyPayload($payment, ['TradeAmt' => '1']))->assertSeeText('0|Amount mismatch', false);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->fresh()->status);
    }

    public function test_an_unknown_trade_number_is_rejected(): void
    {
        $payment = $this->startPayment($this->makeReservation());
        $payment->merchant_trade_no = 'DOESNOTEXIST00000001';

        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('0|Trade not found', false);
    }

    // ── 冪等 ────────────────────────────────────────────────────────────

    public function test_a_repeated_notification_is_acknowledged_but_not_processed_again(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        $payload = $this->notifyPayload($payment);

        $this->sendNotify($payload)->assertSeeText('1|OK', false);
        $paidAt = $payment->fresh()->paid_at;

        Carbon::setTestNow(now()->addMinutes(5)); // 綠界稍後重送
        $this->sendNotify($payload)->assertSeeText('1|OK', false);   // 仍要回 1|OK，否則綠界會一直重送
        $this->sendNotify($payload)->assertSeeText('1|OK', false);

        $this->assertEquals($paidAt, $payment->fresh()->paid_at, '重送不可改動已處理的付款');
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(2, $this->slot->fresh()->booked, '重送不可重複扣名額');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_failure_notification_arriving_after_success_does_not_undo_it(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);

        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false);
        $this->sendNotify($this->notifyPayload($payment, ['RtnCode' => '10100058', 'RtnMsg' => 'late failure']))->assertSeeText('1|OK', false);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    // ── 付款失敗 ────────────────────────────────────────────────────────

    public function test_a_failure_notification_is_recorded_and_the_customer_can_retry(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);

        $this->sendNotify($this->notifyPayload($payment, ['RtnCode' => '10100058', 'RtnMsg' => '授權失敗']))->assertSeeText('1|OK', false);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('授權失敗', $payment->failure_reason);
        $reservation->refresh();
        $this->assertSame(ReservationStatus::PendingPayment, $reservation->status, '付款失敗不影響保留，顧客在期限內可以再試');
        $this->assertSame(PaymentStatus::Failed, $reservation->payment_status);
        $this->assertSame(2, $this->slot->fresh()->booked);

        // 重試：新的付款，訂位回到付款中
        $retry = $this->startPayment($reservation);
        $this->assertNotSame($payment->id, $retry->id);
        $this->assertSame(PaymentStatus::Pending, $reservation->fresh()->payment_status);

        $this->sendNotify($this->notifyPayload($retry))->assertSeeText('1|OK', false);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    // ── 重複付款 ────────────────────────────────────────────────────────

    public function test_a_second_payment_for_an_already_paid_reservation_is_flagged_for_refund(): void
    {
        $reservation = $this->makeReservation();
        $first = $this->startPayment($reservation);
        $second = $this->startPayment($reservation); // 顧客開了兩個分頁，各自進了綠界

        $this->sendNotify($this->notifyPayload($first))->assertSeeText('1|OK', false);
        $this->sendNotify($this->notifyPayload($second))->assertSeeText('1|OK', false);

        $this->assertFalse($first->fresh()->needs_refund);
        $second->refresh();
        $this->assertSame(PaymentStatus::Refunding, $second->status, '錢收到了，已自動進入退款流程');
        $this->assertTrue($second->needs_refund);
        $this->assertSame('DUPLICATE_PAYMENT', $second->refund_reason);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $reservation->fresh()->payment_status, '訂位本身仍是已付款：第一筆是正常的，只有多收的第二筆要退');
        $this->assertSame(2, $this->slot->fresh()->booked);

        $refund = \App\Models\Refund::sole();
        $this->assertSame($second->id, $refund->payment_id);
        $this->assertSame(400, $refund->amount);
        $this->assertSame('DUPLICATE_PAYMENT', $refund->reason);
        $this->assertSame('system', $refund->requested_by_type);
        $this->assertSame(\App\Enums\RefundStatus::Requested, $refund->status);
    }

    // ── 晚到的付款（訂位已逾時釋放）──────────────────────────────────────

    private function expire(Reservation $reservation): void
    {
        Carbon::setTestNow('2026-10-05 10:20:00');
        $this->artisan('reservations:release-expired')->assertSuccessful();
        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(0, $this->slot->fresh()->booked, '逾時釋放應歸還名額');
    }

    public function test_a_late_payment_reinstates_the_reservation_when_the_seats_are_still_free(): void
    {
        $reservation = $this->makeReservation(['party_size' => 3]);
        $payment = $this->startPayment($reservation);
        $this->makeHttpUnavailable();
        $this->expire($reservation);

        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false);

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(PaymentStatus::Paid, $reservation->payment_status);
        $this->assertSame(3, $this->slot->fresh()->booked, '補回時要重新佔名額');
        $this->assertDatabaseCount('reservation_tables', 1);
        $this->assertFalse($payment->fresh()->needs_refund);
    }

    public function test_a_late_payment_is_flagged_for_refund_when_the_capacity_was_taken(): void
    {
        $reservation = $this->makeReservation(['party_size' => 3]);
        $payment = $this->startPayment($reservation);
        $this->makeHttpUnavailable();
        $this->expire($reservation);

        // 釋放之後，別人把這個時段訂滿了
        $this->slot->update(['booked' => 20]);

        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false);

        $payment->refresh();
        $this->assertTrue($payment->needs_refund);
        $this->assertSame('LATE_PAYMENT_SLOT_TAKEN', $payment->refund_reason);
        $this->assertSame(PaymentStatus::Refunding, $payment->status, '錢確實收到了，已自動進入退款流程');
        $reservation->refresh();
        $this->assertSame(ReservationStatus::Expired, $reservation->status, '訂位無法成立');
        $this->assertSame(PaymentStatus::Refunding, $reservation->payment_status);
        $this->assertSame(20, $this->slot->fresh()->booked, '不可超賣');

        $this->assertSame('LATE_PAYMENT_SLOT_TAKEN', \App\Models\Refund::sole()->reason);
    }

    public function test_a_late_payment_rolls_back_the_capacity_it_took_when_no_table_is_free(): void
    {
        $reservation = $this->makeReservation(['party_size' => 2]);
        $payment = $this->startPayment($reservation);
        $this->makeHttpUnavailable();
        $this->expire($reservation);

        // 名額還夠，但所有桌子都被占走了
        foreach ($this->store->diningTables as $table) {
            $this->occupy($this->slot, $table);
        }

        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false);

        $this->assertSame('LATE_PAYMENT_SLOT_TAKEN', $payment->fresh()->refund_reason);
        $this->assertSame(0, $this->slot->fresh()->booked, '佔了名額但分配桌位失敗，名額要退回去');
        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    public function test_a_payment_arriving_after_the_customer_cancelled_is_flagged_for_refund(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        $this->postJson('/api/reservations/cancel', ['code' => $reservation->code, 'phone' => $reservation->phone])->assertOk();

        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false);

        $payment->refresh();
        $this->assertTrue($payment->needs_refund);
        $this->assertSame('PAID_AFTER_CANCEL', $payment->refund_reason);
        $this->assertSame(ReservationStatus::Cancelled, $reservation->fresh()->status);
        $this->assertSame(0, $this->slot->fresh()->booked);
    }

    /** 逾時釋放前會向綠界對帳；這些測試重點在通知，所以讓對帳「查不到」，單純走逾時釋放。 */
    private function makeHttpUnavailable(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 500)]);
        config(['ecpay.max_unverified_hold_minutes' => 0]);
    }

    public function test_the_notify_endpoint_is_not_rate_limited(): void
    {
        $payment = $this->startPayment($this->makeReservation());
        $payload = $this->notifyPayload($payment);

        // 綠界重送時來源 IP 很集中，不能被 IP 限流誤擋
        for ($i = 0; $i < 30; $i++) {
            $this->sendNotify($payload)->assertOk()->assertSeeText('1|OK', false);
        }
    }
}
