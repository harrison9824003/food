<?php

namespace Tests\Feature\Erp;

use App\Enums\OutboxStatus;
use App\Enums\ReservationStatus;
use App\Erp\OutboxRecorder;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Concerns\CreatesPayments;
use Tests\TestCase;

/**
 * Outbox Pattern 的核心保證：訂位的異動與「要通知 ERP」寫在同一個交易，同進同退。
 */
class OutboxRecordingTest extends TestCase
{
    use CreatesPayments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPayments();
    }

    private function events(): \Illuminate\Support\Collection
    {
        return OutboxEvent::orderBy('id')->get();
    }

    public function test_creating_a_reservation_writes_a_pending_event_with_a_full_snapshot(): void
    {
        $reservation = $this->makeReservation(['party_size' => 3, 'note' => '靠窗']);

        $event = $this->events()->sole();
        $this->assertSame(OutboxRecorder::CREATED, $event->event_type);
        $this->assertSame('reservation', $event->aggregate_type);
        $this->assertSame($reservation->id, $event->aggregate_id);
        $this->assertSame($reservation->store_id, $event->store_id);
        $this->assertSame(OutboxStatus::Pending, $event->status);
        $this->assertSame(0, $event->attempts);
        $this->assertEquals(now(), $event->next_attempt_at);

        $data = $event->payload['reservation'];
        $this->assertSame($reservation->code, $data['code']);
        $this->assertSame('pending_payment', $data['status']);
        $this->assertSame('2026-10-06', $data['date']);
        $this->assertSame('12:00', $data['time']);
        $this->assertSame(3, $data['party_size']);
        $this->assertSame('靠窗', $data['note']);
        $this->assertSame(600, $data['deposit_amount']);
        $this->assertSame($this->store->name, $data['store_name']);
    }

    public function test_the_event_and_the_reservation_are_written_together_or_not_at_all(): void
    {
        // 讓「寫入桌位關聯」失敗，整個訂位交易回滾：outbox 事件也必須一起消失
        $this->store->diningTables()->delete();

        $this->postJson('/api/reservations', $this->bookingPayload())->assertStatus(409);

        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, OutboxEvent::count(), '訂位沒成立，就不能有通知 ERP 的事件');
    }

    public function test_an_event_written_inside_a_rolled_back_transaction_disappears(): void
    {
        $reservation = $this->makeReservation();
        $before = OutboxEvent::count();

        try {
            DB::transaction(function () use ($reservation) {
                app(OutboxRecorder::class)->record($reservation, OutboxRecorder::STATUS_CHANGED);
                throw new RuntimeException('後面的步驟失敗了');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($before, OutboxEvent::count());
    }

    public function test_recording_outside_a_transaction_is_a_programming_error(): void
    {
        $reservation = $this->makeReservation();

        // 測試本身包在交易裡，所以先把外層交易收掉再驗證檢查有效
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->expectException(LogicException::class);
        app(OutboxRecorder::class)->record($reservation, OutboxRecorder::STATUS_CHANGED);
    }

    public function test_every_status_change_is_recorded_with_from_and_to(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false);

        $events = $this->events();
        $this->assertSame([OutboxRecorder::CREATED, OutboxRecorder::STATUS_CHANGED], $events->pluck('event_type')->all());
        $this->assertSame('pending_payment', $events[1]->payload['from']);
        $this->assertSame('confirmed', $events[1]->payload['to']);
        $this->assertSame('confirmed', $events[1]->payload['reservation']['status'], '事件是變更後的完整快照');
        $this->assertSame('paid', $events[1]->payload['reservation']['payment_status']);
    }

    public function test_cancel_expire_and_staff_actions_are_recorded(): void
    {
        $this->store->update(['deposit_per_person' => 0]);
        $cancelled = $this->makeReservation(['phone' => '0911000001']);
        $arrived = $this->makeReservation(['phone' => '0911000002']);

        $this->postJson('/api/reservations/cancel', ['code' => $cancelled->code, 'phone' => $cancelled->phone])->assertOk();
        app(\App\Booking\ReservationStatusService::class)->transition($arrived, ReservationStatus::Arrived);

        $changes = OutboxEvent::where('event_type', OutboxRecorder::STATUS_CHANGED)->orderBy('id')->get();
        $this->assertSame(['cancelled', 'arrived'], $changes->pluck('payload.to')->all());
    }

    public function test_a_repeated_cancel_does_not_record_a_second_event(): void
    {
        $this->store->update(['deposit_per_person' => 0]);
        $reservation = $this->makeReservation();

        $cancel = fn () => $this->postJson('/api/reservations/cancel', ['code' => $reservation->code, 'phone' => $reservation->phone])->assertOk();
        $cancel();
        $count = OutboxEvent::count();
        $cancel();

        $this->assertSame($count, OutboxEvent::count(), '冪等的重複請求不該再通知 ERP 一次');
    }

    public function test_a_late_payment_reinstatement_is_recorded(): void
    {
        $reservation = $this->makeReservation();
        $payment = $this->startPayment($reservation);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 500)]);
        config(['ecpay.max_unverified_hold_minutes' => 0]);
        Carbon::setTestNow('2026-10-05 10:20:00');
        $this->artisan('reservations:release-expired')->assertSuccessful();

        $this->sendNotify($this->notifyPayload($payment))->assertSeeText('1|OK', false);

        $this->assertSame(
            ['pending_payment→expired', 'expired→confirmed'],
            OutboxEvent::where('event_type', OutboxRecorder::STATUS_CHANGED)->orderBy('id')->get()
                ->map(fn ($e) => $e->payload['from'].'→'.$e->payload['to'])->all(),
        );
    }

    public function test_events_are_scoped_to_a_store_for_permission_checks(): void
    {
        $this->makeReservation();

        $this->assertSame($this->store->id, OutboxEvent::sole()->store_id);
    }

    public function test_the_payload_of_one_event_does_not_depend_on_other_events(): void
    {
        // 快照而非差異：亂序到達或重送時，每個事件各自都是完整的，ERP 不必回頭查我們
        $reservation = $this->makeReservation();
        $this->postJson('/api/reservations/cancel', ['code' => $reservation->code, 'phone' => $reservation->phone])->assertOk();

        foreach ($this->events() as $event) {
            foreach (['code', 'status', 'date', 'time', 'party_size', 'customer_name', 'phone', 'store_name'] as $field) {
                $this->assertArrayHasKey($field, $event->payload['reservation'], "{$event->event_type} 缺少 {$field}");
            }
        }
    }
}
