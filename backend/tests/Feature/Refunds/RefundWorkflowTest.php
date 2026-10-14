<?php

namespace Tests\Feature\Refunds;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Erp\OutboxRecorder;
use App\Models\OutboxEvent;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Store;
use App\Models\User;
use App\Payments\CheckMacValue;
use App\Refunds\RefundService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRefunds;
use Tests\TestCase;

/**
 * 退款流程：申請 → 簽核 → 呼叫金流商 → 回寫狀態。
 */
class RefundWorkflowTest extends TestCase
{
    use CreatesRefunds;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRefunds();
    }

    /** 顧客取消了一張已付款的訂位，產生一筆待簽核的退款。 */
    private function pendingRefund(array $overrides = []): Refund
    {
        $reservation = $this->paidReservation($overrides);
        $this->customerCancels($reservation)->assertOk();

        return $this->refundOf($reservation);
    }

    private function loginAs(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    private function approve(Refund $refund)
    {
        return $this->postJson("/api/admin/refunds/{$refund->id}/approve");
    }

    // ── 簽核之前不碰金流商 ──────────────────────────────────────────────

    public function test_nothing_is_sent_to_the_gateway_before_approval(): void
    {
        $refund = $this->pendingRefund();

        $this->assertSame(0, $this->refundApiCalls());
        $this->assertSame('skipped', app(RefundService::class)->execute($refund->id), '就算有人直接觸發執行，沒核准的退款也不會動作');
        $this->assertSame(0, $this->refundApiCalls());
        $this->assertSame(RefundStatus::Requested, $refund->fresh()->status);
    }

    // ── 核准 → 退款成功 ─────────────────────────────────────────────────

    public function test_approving_refunds_through_the_gateway_and_writes_everything_back(): void
    {
        $refund = $this->pendingRefund(['party_size' => 2]);
        $manager = $this->loginAs(User::factory()->storeManager($this->store)->create());

        $this->approve($refund)->assertOk()->assertJsonPath('message', '已核准，退款處理中');

        $refund->refresh();
        $this->assertSame(RefundStatus::Completed, $refund->status);
        $this->assertSame($manager->id, $refund->approved_by);
        $this->assertNotNull($refund->approved_at);
        $this->assertNotNull($refund->completed_at);
        $this->assertSame(1, $refund->attempts);
        $this->assertNull($refund->failure_reason);

        $payment = $refund->payment->fresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertFalse($payment->needs_refund);
        $this->assertSame(PaymentStatus::Refunded, $refund->reservation->fresh()->payment_status);
    }

    public function test_the_gateway_request_is_signed_and_refunds_exactly_the_paid_amount(): void
    {
        $refund = $this->pendingRefund(['party_size' => 3]); // $600
        $payment = $refund->payment;
        $this->loginAs(User::factory()->hqAdmin()->create());

        $this->approve($refund)->assertOk();

        Http::assertSent(function ($request) use ($payment) {
            if ($request->url() !== self::DO_ACTION) {
                return false;
            }

            return $request['MerchantID'] === '2000132'
                && $request['MerchantTradeNo'] === $payment->merchant_trade_no
                && $request['TradeNo'] === $payment->ecpay_trade_no
                && $request['Action'] === 'R'
                && $request['TotalAmount'] === '600'
                && CheckMacValue::verify($request->data(), config('ecpay.hash_key'), config('ecpay.hash_iv'));
        });
        $this->assertSame(1, $this->refundApiCalls());
    }

    public function test_approving_twice_refunds_once(): void
    {
        $refund = $this->pendingRefund();
        $this->loginAs(User::factory()->hqAdmin()->create());

        $this->approve($refund)->assertOk();
        $this->approve($refund)->assertOk()->assertJsonPath('status', 'completed'); // 連點：什麼都不做

        $this->assertSame(1, $this->refundApiCalls(), '同一筆錢不可退兩次');
        $this->assertSame(1, Refund::count());
    }

    public function test_executing_an_already_completed_refund_is_a_no_op(): void
    {
        $refund = $this->pendingRefund();
        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->approve($refund)->assertOk();

        $this->assertSame('skipped', app(RefundService::class)->execute($refund->id));
        $this->assertSame(1, $this->refundApiCalls());
    }

    // ── 金流商拒絕 ──────────────────────────────────────────────────────

    public function test_a_gateway_refusal_marks_the_refund_failed_without_retrying(): void
    {
        $refund = $this->pendingRefund();
        $this->refundApiReturns(0, '尚未關帳，無法退刷');
        $this->loginAs(User::factory()->hqAdmin()->create());

        $this->approve($refund)->assertOk();

        $refund->refresh();
        $this->assertSame(RefundStatus::Failed, $refund->status);
        $this->assertStringContainsString('尚未關帳', $refund->failure_reason);
        $this->assertSame(1, $this->refundApiCalls(), '金流商明確拒絕：重試不會改變結果');
        $this->assertSame(PaymentStatus::Refunding, $refund->payment->fresh()->status, '錢還沒退回去');
        $this->assertSame(PaymentStatus::Refunding, $refund->reservation->fresh()->payment_status);
    }

    public function test_a_failed_refund_can_be_retried_by_a_manager(): void
    {
        $refund = $this->pendingRefund();
        $this->refundApiReturns(0, '尚未關帳');
        $manager = $this->loginAs(User::factory()->storeManager($this->store)->create());
        $this->approve($refund)->assertOk();
        $this->assertSame(RefundStatus::Failed, $refund->fresh()->status);

        // 到綠界後台關帳之後
        $this->refundApiReturns(1, 'OK');
        $this->postJson("/api/admin/refunds/{$refund->id}/retry")->assertOk();

        $refund->refresh();
        $this->assertSame(RefundStatus::Completed, $refund->status);
        $this->assertSame(1, $refund->attempts, '人工重試時嘗試次數歸零');
        $this->assertSame(2, $this->refundApiCalls());
        $this->assertSame($manager->id, $refund->approved_by);
    }

    public function test_only_failed_refunds_can_be_retried(): void
    {
        $refund = $this->pendingRefund();
        $this->loginAs(User::factory()->hqAdmin()->create());

        $this->postJson("/api/admin/refunds/{$refund->id}/retry")->assertStatus(409)->assertJsonPath('code', 'INVALID_REFUND_STATE');
        $this->assertSame(0, $this->refundApiCalls());
    }

    // ── 結果未知（沒拿到金流商的答覆）──────────────────────────────────

    public function test_an_unknown_result_is_not_treated_as_a_failure_and_is_retried_later(): void
    {
        $refund = $this->pendingRefund();
        $this->refundApiIsUnreachable();
        $this->loginAs(User::factory()->hqAdmin()->create());

        $this->approve($refund)->assertOk();

        $refund->refresh();
        $this->assertSame(RefundStatus::Approved, $refund->status, '不是失敗：可能其實已經退了');
        $this->assertSame(1, $refund->attempts);
        $this->assertEquals(now()->addSeconds(60), $refund->next_attempt_at);
        $this->assertStringContainsString('502', $refund->failure_reason);
        $this->assertSame(PaymentStatus::Refunding, $refund->payment->fresh()->status);
    }

    public function test_an_unknown_result_is_not_retried_before_its_time(): void
    {
        $refund = $this->pendingRefund();
        $this->refundApiIsUnreachable();
        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->approve($refund)->assertOk();

        Carbon::setTestNow(now()->addSeconds(30));

        $this->assertSame('skipped', app(RefundService::class)->execute($refund->id));
        $this->assertSame(1, $this->refundApiCalls());
    }

    public function test_the_retry_after_an_unknown_result_can_complete_the_refund(): void
    {
        $refund = $this->pendingRefund();
        $this->refundApiIsUnreachable();
        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->approve($refund)->assertOk();

        $this->refundApiReturns(1, 'OK');
        Carbon::setTestNow(now()->addSeconds(61));

        $this->assertSame('completed', app(RefundService::class)->execute($refund->id));
        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
        $this->assertSame(2, $this->refundApiCalls());
    }

    public function test_after_repeated_unknown_results_it_stops_and_asks_a_human_instead_of_risking_a_double_refund(): void
    {
        $refund = $this->pendingRefund();
        $this->refundApiIsUnreachable();
        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->approve($refund)->assertOk();

        foreach ([61, 301] as $seconds) { // 第 2、3 次
            Carbon::setTestNow(now()->addSeconds($seconds));
            app(RefundService::class)->execute($refund->id);
        }

        $refund->refresh();
        $this->assertSame(RefundStatus::Failed, $refund->status);
        $this->assertSame(3, $refund->attempts);
        $this->assertStringContainsString('結果未知', $refund->failure_reason);
        $this->assertStringContainsString('金流商後台', $refund->failure_reason);
        $this->assertSame(3, $this->refundApiCalls());

        Carbon::setTestNow(now()->addDay());
        $this->assertSame('skipped', app(RefundService::class)->execute($refund->id), '不再自動重試');
        $this->assertSame(3, $this->refundApiCalls());
    }

    public function test_the_scheduler_picks_up_refunds_that_are_due_for_another_attempt(): void
    {
        $refund = $this->pendingRefund();
        $this->refundApiIsUnreachable();
        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->approve($refund)->assertOk();
        $this->refundApiReturns(1, 'OK');

        $this->artisan('refunds:process')->assertSuccessful();
        $this->assertSame(1, $this->refundApiCalls(), '還沒到重試時間');

        Carbon::setTestNow(now()->addSeconds(61));
        $this->artisan('refunds:process')->assertSuccessful();

        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
    }

    public function test_the_scheduler_recovers_an_approved_refund_whose_job_was_lost(): void
    {
        $refund = $this->pendingRefund();
        // 核准了，但佇列遺失、沒有任何程式去執行
        $refund->update(['status' => RefundStatus::Approved, 'approved_by' => User::factory()->hqAdmin()->create()->id, 'approved_at' => now()]);

        $this->artisan('refunds:process')->assertSuccessful();
        $this->assertSame(0, $this->refundApiCalls(), '剛核准，再等一下（佇列通常幾秒內就會處理）');

        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('refunds:process')->assertSuccessful();

        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
    }

    public function test_the_refund_command_is_scheduled_every_minute(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'refunds:process'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    // ── 駁回 ────────────────────────────────────────────────────────────

    public function test_rejecting_keeps_the_deposit_and_returns_the_payment_to_paid(): void
    {
        $refund = $this->pendingRefund();
        $manager = $this->loginAs(User::factory()->storeManager($this->store)->create());

        $this->postJson("/api/admin/refunds/{$refund->id}/reject", ['reason' => '顧客當天有來用餐'])->assertOk();

        $refund->refresh();
        $this->assertSame(RefundStatus::Rejected, $refund->status);
        $this->assertSame('顧客當天有來用餐', $refund->reject_reason);
        $this->assertSame($manager->id, $refund->approved_by);
        $this->assertNull($refund->active_payment_id, '釋出「同時只能一筆有效退款」的名額');
        $this->assertSame(PaymentStatus::Paid, $refund->payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $refund->reservation->fresh()->payment_status);
        $this->assertSame(0, $this->refundApiCalls());
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $refund = $this->pendingRefund();
        $this->loginAs(User::factory()->hqAdmin()->create());

        $this->postJson("/api/admin/refunds/{$refund->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors('reason', 'errors');
        $this->postJson("/api/admin/refunds/{$refund->id}/reject", ['reason' => str_repeat('長', 201)])->assertStatus(422);
        $this->assertSame(RefundStatus::Requested, $refund->fresh()->status);
    }

    public function test_a_rejected_refund_cannot_be_approved_and_an_approved_one_cannot_be_rejected(): void
    {
        $rejected = $this->pendingRefund(['phone' => '0911000001']);
        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->postJson("/api/admin/refunds/{$rejected->id}/reject", ['reason' => '不符規定'])->assertOk();

        $this->approve($rejected)->assertStatus(409)->assertJsonPath('code', 'INVALID_REFUND_STATE');

        $done = $this->pendingRefund(['phone' => '0911000002']);
        $this->approve($done)->assertOk();
        $this->postJson("/api/admin/refunds/{$done->id}/reject", ['reason' => '反悔'])->assertStatus(409);
        $this->assertSame(RefundStatus::Completed, $done->fresh()->status);
    }

    // ── 權限與職責分離 ──────────────────────────────────────────────────

    public function test_who_may_decide(): void
    {
        $refund = $this->pendingRefund();
        $otherStore = Store::factory()->create();

        $this->loginAs(User::factory()->staff($this->store)->create());
        $this->approve($refund)->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        $this->postJson("/api/admin/refunds/{$refund->id}/reject", ['reason' => 'x'])->assertStatus(403);

        $this->loginAs(User::factory()->storeManager($otherStore)->create());
        $this->approve($refund)->assertStatus(403);

        $this->assertSame(RefundStatus::Requested, $refund->fresh()->status);
        $this->assertSame(0, $this->refundApiCalls());

        $this->loginAs(User::factory()->storeManager($this->store)->create());
        $this->approve($refund)->assertOk();
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $refund = $this->pendingRefund();

        $this->postJson("/api/admin/refunds/{$refund->id}/approve")->assertStatus(401);
        $this->getJson('/api/admin/refunds')->assertStatus(401);
    }

    public function test_nobody_can_approve_a_refund_they_requested_themselves(): void
    {
        $reservation = $this->paidReservation();
        $requester = $this->loginAs(User::factory()->storeManager($this->store)->create());
        $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['status' => 'cancelled'])->assertOk();
        $refund = $this->refundOf($reservation);

        $this->approve($refund)->assertStatus(403)->assertJsonPath('code', 'SELF_APPROVAL');
        $this->assertSame(RefundStatus::Requested, $refund->fresh()->status);
        $this->assertSame(0, $this->refundApiCalls());

        // 另一位主管（或總部）可以
        $this->loginAs(User::factory()->storeManager($this->store)->create());
        $this->approve($refund)->assertOk();
        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
        $this->assertNotSame($requester->id, $refund->fresh()->approved_by);
    }

    public function test_a_customer_requested_refund_can_be_approved_by_any_manager(): void
    {
        $refund = $this->pendingRefund(); // 申請人是顧客，不是後台使用者
        $this->loginAs(User::factory()->storeManager($this->store)->create());

        $this->approve($refund)->assertOk();
    }

    // ── 清單 ────────────────────────────────────────────────────────────

    public function test_the_list_defaults_to_pending_approval_and_is_scoped_by_store(): void
    {
        $mine = $this->pendingRefund(['phone' => '0911000001']);
        $other = Store::factory()->create(['deposit_per_person' => 100]);
        $otherPayment = $mine->payment->replicate()->forceFill(['merchant_trade_no' => 'OTHER'.$mine->id, 'ecpay_trade_no' => '9990001']);
        $otherPayment->save();
        $mine->replicate()->forceFill(['store_id' => $other->id, 'payment_id' => $otherPayment->id, 'active_payment_id' => $otherPayment->id])->save();

        $this->loginAs(User::factory()->storeManager($this->store)->create());
        $response = $this->getJson('/api/admin/refunds')->assertOk()
            ->assertJsonPath('counts.requested', 1)
            ->assertJsonCount(1, 'refunds')
            ->assertJsonPath('refunds.0.id', $mine->id)
            ->assertJsonPath('refunds.0.amount', 400)
            ->assertJsonPath('refunds.0.reason_label', '顧客取消')
            ->assertJsonPath('refunds.0.requested_by', '顧客')
            ->assertJsonPath('refunds.0.reservation_code', $mine->reservation->code);

        $this->assertArrayNotHasKey('phone', $response->json('refunds.0'), '清單不外洩顧客手機');

        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->getJson('/api/admin/refunds')->assertOk()->assertJsonPath('counts.requested', 2)->assertJsonCount(2, 'refunds');
    }

    public function test_the_list_can_be_filtered_by_status_and_hidden_from_staff(): void
    {
        $refund = $this->pendingRefund();
        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->approve($refund)->assertOk();

        $this->getJson('/api/admin/refunds?status=completed')->assertOk()->assertJsonCount(1, 'refunds')
            ->assertJsonPath('refunds.0.status_label', '已退款')->assertJsonPath('counts.completed', 1);
        $this->getJson('/api/admin/refunds')->assertOk()->assertJsonCount(0, 'refunds');
        $this->getJson('/api/admin/refunds?status=bogus')->assertStatus(422);

        $this->loginAs(User::factory()->staff($this->store)->create());
        $this->getJson('/api/admin/refunds')->assertStatus(403);
    }

    public function test_the_list_marks_refunds_the_current_user_requested_themselves(): void
    {
        $reservation = $this->paidReservation();
        $this->loginAs(User::factory()->storeManager($this->store)->create());
        $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->getJson('/api/admin/refunds')->assertOk()->assertJsonPath('refunds.0.requested_by_me', true);

        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->getJson('/api/admin/refunds')->assertOk()->assertJsonPath('refunds.0.requested_by_me', false);
    }

    // ── 重複退款的防線 ──────────────────────────────────────────────────

    public function test_requesting_a_refund_twice_for_one_payment_returns_the_same_refund(): void
    {
        $reservation = $this->paidReservation();
        $payment = $this->paymentOf($reservation);

        $result = DB::transaction(function () use ($reservation, $payment) {
            $service = app(RefundService::class);

            return [
                $service->request($reservation, $payment, Refund::STORE_CANCEL, 'staff'),
                $service->request($reservation, $payment, Refund::STORE_CANCEL, 'staff'),
            ];
        });

        $this->assertSame($result[0]->id, $result[1]->id);
        $this->assertSame(1, Refund::count());
    }

    public function test_the_database_itself_refuses_a_second_active_refund_for_the_same_payment(): void
    {
        $refund = $this->pendingRefund();

        // 就算應用層的檢查有漏洞，資料庫的 UNIQUE 是最後一道防線
        $this->expectException(QueryException::class);
        Refund::create([
            'payment_id' => $refund->payment_id, 'active_payment_id' => $refund->payment_id, 'reservation_id' => $refund->reservation_id,
            'store_id' => $refund->store_id, 'amount' => 400, 'reason' => 'X', 'requested_by_type' => 'staff', 'status' => RefundStatus::Requested,
        ]);
    }

    public function test_a_rejected_refund_does_not_block_a_new_request(): void
    {
        $refund = $this->pendingRefund();
        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->postJson("/api/admin/refunds/{$refund->id}/reject", ['reason' => '先不退'])->assertOk();

        $again = DB::transaction(fn () => app(RefundService::class)->request(
            $refund->reservation, $refund->payment, Refund::STORE_CANCEL, 'staff',
        ));

        $this->assertNotSame($refund->id, $again->id);
        $this->assertSame(2, Refund::count());
    }

    // ── 本機模擬 ────────────────────────────────────────────────────────

    public function test_refunds_of_simulator_payments_are_simulated_and_never_reach_the_gateway(): void
    {
        $refund = $this->pendingRefund();
        $refund->payment->update(['confirmed_via' => 'simulator']);
        config(['ecpay.simulator_enabled' => true]);
        $this->loginAs(User::factory()->hqAdmin()->create());

        $this->approve($refund)->assertOk();

        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
        $this->assertSame(0, $this->refundApiCalls(), '綠界沒有這筆交易，不能真的去退');
    }

    public function test_simulation_never_applies_to_real_payments_or_in_production(): void
    {
        // 真實的付款（confirmed_via = notify）：即使模擬器開著也要真的呼叫綠界
        $real = $this->pendingRefund(['phone' => '0911000001']);
        config(['ecpay.simulator_enabled' => true]);
        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->approve($real)->assertOk();
        $this->assertSame(1, $this->refundApiCalls());

        // 模擬器的付款，但在正式環境：同樣要真的呼叫
        $simulated = $this->pendingRefund(['phone' => '0911000002']);
        $simulated->payment->update(['confirmed_via' => 'simulator']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->approve($simulated)->assertOk();
        $this->assertSame(2, $this->refundApiCalls());
    }

    // ── 通知 ERP ────────────────────────────────────────────────────────

    public function test_every_refund_state_change_is_recorded_for_the_erp_in_order(): void
    {
        $refund = $this->pendingRefund();
        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->approve($refund)->assertOk();

        $states = OutboxEvent::where('event_type', OutboxRecorder::REFUND_CHANGED)->orderBy('id')->get()
            ->map(fn ($e) => $e->payload['refund']['status'])->all();

        $this->assertSame(['requested', 'approved', 'completed'], $states);
    }

    // ── 付款端標記的待退款：自動進入簽核 ─────────────────────────────────

    public function test_a_late_payment_that_cannot_be_fulfilled_enters_the_refund_flow_and_can_be_refunded(): void
    {
        $reservation = $this->makeReservation(['party_size' => 3]);
        $payment = $this->startPayment($reservation);
        Http::fake(['*QueryTradeInfo*' => Http::response('', 500)]);
        config(['ecpay.max_unverified_hold_minutes' => 0]);
        Carbon::setTestNow('2026-10-05 10:20:00');
        $this->artisan('reservations:release-expired')->assertSuccessful();
        $this->slot->update(['booked' => 20]); // 座位被別人訂滿

        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false);

        $refund = Refund::sole();
        $this->assertSame('LATE_PAYMENT_SLOT_TAKEN', $refund->reason);
        $this->assertSame('system', $refund->requested_by_type);
        $this->assertSame(600, $refund->amount);
        $this->assertSame(RefundStatus::Requested, $refund->status);

        $this->loginAs(User::factory()->storeManager($this->store)->create());
        $this->approve($refund)->assertOk();

        $this->assertSame(RefundStatus::Completed, $refund->fresh()->status);
        $this->assertFalse($payment->fresh()->needs_refund, '退完就不再是待退款');
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
    }

    public function test_a_rejected_payment_side_refund_stops_being_flagged(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        $this->customerCancels($reservation)->assertOk();
        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false); // 取消後才付款

        $refund = Refund::sole();
        $this->assertSame('PAID_AFTER_CANCEL', $refund->reason);
        $this->assertTrue($payment->fresh()->needs_refund);

        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->postJson("/api/admin/refunds/{$refund->id}/reject", ['reason' => '經查證為誤判'])->assertOk();

        $this->assertFalse($payment->fresh()->needs_refund);
    }

    // ── 並發情境下的安全 ────────────────────────────────────────────────

    public function test_refund_status_is_visible_to_the_customer(): void
    {
        $refund = $this->pendingRefund();
        $reservation = Reservation::find($refund->reservation_id);
        $lookup = fn () => $this->postJson('/api/reservations/lookup', ['code' => $reservation->code, 'phone' => $reservation->phone]);

        $lookup()->assertJsonPath('refund.status_label', '待簽核');

        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->approve($refund)->assertOk();

        $lookup()->assertJsonPath('refund.status_label', '已退款')->assertJsonPath('payment_status', 'refunded');
    }
}
