<?php

namespace Tests\Feature\Refunds;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\ReservationStatus;
use App\Erp\OutboxRecorder;
use App\Models\OutboxEvent;
use App\Models\Refund;
use App\Models\User;
use App\Refunds\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRefunds;
use Tests\TestCase;

/**
 * 取消與退款政策：用餐前 24 小時（含）以前取消全額退，之後取消不退（需顧客明確確認）；門市代為取消一律全額退。
 * 用餐 2026-10-06 12:00 → 免費取消期限 2026-10-05 12:00；測試的「現在」是 2026-10-05 10:00。
 */
class CancellationRefundTest extends TestCase
{
    use CreatesRefunds;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRefunds();
    }

    // ── 期限內：全額退 ──────────────────────────────────────────────────

    public function test_cancelling_before_the_deadline_creates_a_full_refund_request(): void
    {
        $reservation = $this->paidReservation(['party_size' => 2]); // 訂金 $400

        $this->customerCancels($reservation)->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('payment_status', 'refunding')
            ->assertJsonPath('refund.status', 'requested')
            ->assertJsonPath('refund.status_label', '待簽核')
            ->assertJsonPath('refund.amount', 400);

        $refund = $this->refundOf($reservation);
        $this->assertSame(RefundStatus::Requested, $refund->status);
        $this->assertSame(400, $refund->amount);
        $this->assertSame(Refund::CUSTOMER_CANCEL, $refund->reason);
        $this->assertSame('customer', $refund->requested_by_type);
        $this->assertNull($refund->requested_by_id);
        $this->assertSame($reservation->store_id, $refund->store_id);
        $this->assertSame($this->paymentOf($reservation)->id, $refund->payment_id);

        $this->assertSame(PaymentStatus::Refunding, $this->paymentOf($reservation)->status);
        $this->assertSame(0, $this->slot->fresh()->booked, '名額歸還');
        $this->assertDatabaseCount('reservation_tables', 0);
        $this->assertSame(0, $this->refundApiCalls(), '申請階段還沒簽核，不可碰金流商');
    }

    public function test_the_deadline_itself_still_counts_as_in_time(): void
    {
        $reservation = $this->paidReservation();

        Carbon::setTestNow('2026-10-05 12:00:00'); // 剛好用餐前 24 小時

        $this->customerCancels($reservation)->assertOk()->assertJsonPath('refund.status', 'requested');
    }

    public function test_one_second_after_the_deadline_the_deposit_is_forfeited(): void
    {
        $reservation = $this->paidReservation();

        Carbon::setTestNow('2026-10-05 12:00:01');

        $this->customerCancels($reservation)->assertStatus(409)->assertJsonPath('code', 'FORFEIT_CONFIRMATION_REQUIRED');
    }

    // ── 期限後：沒收，需要顧客明確確認 ──────────────────────────────────

    public function test_a_late_cancellation_is_refused_until_the_customer_acknowledges_the_forfeit(): void
    {
        $reservation = $this->paidReservation(['party_size' => 3]); // 訂金 $600
        Carbon::setTestNow('2026-10-06 08:00:00'); // 距離用餐 4 小時

        $this->customerCancels($reservation)->assertStatus(409)
            ->assertJsonPath('code', 'FORFEIT_CONFIRMATION_REQUIRED')
            ->assertJsonPath('errors.deposit', 600)
            ->assertJsonPath('errors.free_cancellation_until', '2026-10-05T12:00:00+08:00');

        // 什麼都不能動：訂位、名額、桌位、付款都維持原狀
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(3, $this->slot->fresh()->booked);
        $this->assertDatabaseCount('reservation_tables', 1);
        $this->assertSame(0, Refund::count());
        $this->assertSame(PaymentStatus::Paid, $this->paymentOf($reservation)->status);
    }

    public function test_after_acknowledging_a_late_cancellation_the_seats_are_released_but_the_deposit_is_kept(): void
    {
        $reservation = $this->paidReservation(['party_size' => 3]);
        Carbon::setTestNow('2026-10-06 08:00:00');

        $this->customerCancels($reservation, acknowledgeForfeit: true)->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('refund', null);

        $this->assertSame(0, $this->slot->fresh()->booked, '座位照樣釋出給其他人');
        $this->assertDatabaseCount('reservation_tables', 0);
        $this->assertSame(0, Refund::count(), '沒收：不建立退款申請');
        $this->assertSame(PaymentStatus::Paid, $this->paymentOf($reservation)->status);
        $this->assertFalse($this->paymentOf($reservation)->needs_refund);
    }

    public function test_acknowledging_is_harmless_when_the_cancellation_is_still_free(): void
    {
        $reservation = $this->paidReservation();

        // 顧客手上是舊畫面，帶了「同意沒收」，但其實還在期限內：照樣退款，不會因為那個旗標就沒收
        $this->customerCancels($reservation, acknowledgeForfeit: true)->assertOk()->assertJsonPath('refund.status', 'requested');
    }

    // ── 顧客在取消之前就看得到結果 ──────────────────────────────────────

    public function test_the_reservation_tells_the_customer_what_cancelling_would_do(): void
    {
        $reservation = $this->paidReservation(['party_size' => 2]);
        $lookup = fn () => $this->postJson('/api/reservations/lookup', ['code' => $reservation->code, 'phone' => $reservation->phone]);

        $lookup()->assertOk()
            ->assertJsonPath('cancellation.refundable', true)
            ->assertJsonPath('cancellation.deposit', 400)
            ->assertJsonPath('cancellation.free_cancellation_until', '2026-10-05T12:00:00+08:00');

        Carbon::setTestNow('2026-10-05 13:00:00');
        $lookup()->assertJsonPath('cancellation.refundable', false);
    }

    public function test_no_cancellation_preview_for_unpaid_free_or_cancelled_reservations(): void
    {
        $unpaid = $this->makeReservation(['phone' => '0911000001']);
        $this->postJson('/api/reservations/lookup', ['code' => $unpaid->code, 'phone' => $unpaid->phone])->assertJsonPath('cancellation', null);

        $this->store->update(['deposit_per_person' => 0]);
        $free = $this->makeReservation(['phone' => '0911000002']);
        $this->postJson('/api/reservations/lookup', ['code' => $free->code, 'phone' => $free->phone])->assertJsonPath('cancellation', null);
    }

    // ── 不受影響的情況 ──────────────────────────────────────────────────

    public function test_unpaid_and_free_reservations_cancel_as_before_without_any_refund(): void
    {
        $unpaid = $this->makeReservation(['phone' => '0911000001']); // 待付款
        $this->store->update(['deposit_per_person' => 0]);
        $free = $this->makeReservation(['phone' => '0911000002']);
        Carbon::setTestNow('2026-10-06 08:00:00'); // 就算超過期限，沒付錢就沒有沒收的問題

        $this->customerCancels($unpaid)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->customerCancels($free)->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame(0, Refund::count());
    }

    public function test_cancelling_twice_creates_only_one_refund(): void
    {
        $reservation = $this->paidReservation();

        $this->customerCancels($reservation)->assertOk();
        $this->customerCancels($reservation)->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame(1, Refund::count());
        $this->assertSame(1, OutboxEvent::where('event_type', OutboxRecorder::REFUND_CHANGED)->count());
    }

    public function test_a_reservation_that_cannot_be_cancelled_gets_the_state_error_not_the_forfeit_prompt(): void
    {
        $reservation = $this->paidReservation();
        $reservation->update(['status' => ReservationStatus::Arrived]);
        Carbon::setTestNow('2026-10-06 08:00:00');

        $this->customerCancels($reservation)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
        $this->assertSame(0, Refund::count());
    }

    // ── 同進同退 ────────────────────────────────────────────────────────

    public function test_the_cancellation_and_the_refund_request_are_written_together_or_not_at_all(): void
    {
        $reservation = $this->paidReservation(['party_size' => 2]);
        $before = OutboxEvent::count();

        // 建立退款申請時出錯：取消也必須一起回滾，否則座位放掉了、錢卻沒有人處理
        $this->mock(RefundService::class)->shouldReceive('request')->andThrow(new \RuntimeException('db hiccup'));

        $this->customerCancels($reservation)->assertStatus(500);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(2, $this->slot->fresh()->booked);
        $this->assertDatabaseCount('reservation_tables', 1);
        $this->assertSame($before, OutboxEvent::count());
    }

    public function test_erp_sees_the_cancellation_before_the_refund_request(): void
    {
        $reservation = $this->paidReservation();
        $this->customerCancels($reservation)->assertOk();

        $events = OutboxEvent::where('aggregate_id', $reservation->id)->orderBy('id')->get();
        $tail = $events->slice(-2)->values();

        $this->assertSame(OutboxRecorder::STATUS_CHANGED, $tail[0]->event_type);
        $this->assertSame('cancelled', $tail[0]->payload['to']);
        $this->assertSame(OutboxRecorder::REFUND_CHANGED, $tail[1]->event_type);
        $this->assertSame('requested', $tail[1]->payload['refund']['status']);
        $this->assertSame(400, $tail[1]->payload['refund']['amount']);
    }

    // ── 門市代為取消：一律全額退，且申請人要記下來 ───────────────────────

    public function test_a_store_cancellation_always_refunds_even_inside_the_free_cancellation_window(): void
    {
        $reservation = $this->paidReservation();
        $manager = User::factory()->storeManager($this->store)->create();
        Sanctum::actingAs($manager);
        Carbon::setTestNow('2026-10-06 08:00:00'); // 顧客自己取消的話已經不退

        $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['status' => 'cancelled'])
            ->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('refund.status', 'requested');

        $refund = $this->refundOf($reservation);
        $this->assertSame(Refund::STORE_CANCEL, $refund->reason);
        $this->assertSame('staff', $refund->requested_by_type);
        $this->assertSame($manager->id, $refund->requested_by_id);
        $this->assertSame(400, $refund->amount);
    }

    public function test_staff_can_cancel_a_paid_reservation_but_that_only_files_a_request(): void
    {
        $reservation = $this->paidReservation();
        Sanctum::actingAs(User::factory()->staff($this->store)->create());

        $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(RefundStatus::Requested, $this->refundOf($reservation)->status, '放行這筆錢要等主管簽核');
        $this->assertSame(0, $this->refundApiCalls());
    }

    public function test_a_store_cancellation_of_an_unpaid_reservation_creates_no_refund(): void
    {
        $reservation = $this->makeReservation();
        Sanctum::actingAs(User::factory()->staff($this->store)->create());

        $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertSame(0, Refund::count());
    }

    public function test_marking_no_show_keeps_the_deposit(): void
    {
        $reservation = $this->paidReservation();
        Sanctum::actingAs(User::factory()->staff($this->store)->create());

        $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['status' => 'no_show'])->assertOk();

        $this->assertSame(0, Refund::count());
        $this->assertSame(PaymentStatus::Paid, $this->paymentOf($reservation)->status);
    }

    public function test_admin_order_list_shows_the_refund(): void
    {
        $reservation = $this->paidReservation();
        $this->customerCancels($reservation)->assertOk();
        Sanctum::actingAs(User::factory()->hqAdmin()->create());

        $this->getJson('/api/admin/reservations')->assertOk()
            ->assertJsonPath('data.0.refund.status', 'requested')
            ->assertJsonPath('data.0.refund.amount', 400);
    }
}
