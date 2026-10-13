<?php

namespace Tests\Feature\Erp;

use App\Enums\OutboxStatus;
use App\Erp\ErpGateway;
use App\Erp\ErpPermanentException;
use App\Erp\ErpTemporaryException;
use App\Erp\HttpErpGateway;
use App\Erp\OutboxDispatcher;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesBookableStore;
use Tests\TestCase;

class OutboxDispatcherTest extends TestCase
{
    use CreatesBookableStore;
    use RefreshDatabase;

    private const URL = 'http://mock-erp:3000/events';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBookable();
        config(['erp.backoff_base_seconds' => 60, 'erp.max_attempts' => 6]);

        // Http::fake() 在同一個測試裡再呼叫不會覆蓋先前的設定，所以只註冊一次，之後用 fakeErp() 切換 ERP 的回應
        Http::fake([self::URL => function ($request) {
            return is_callable($this->erpNext) ? ($this->erpNext)($request) : $this->erpNext;
        }]);
        $this->fakeErp(Http::response('ok'));
    }

    /** @var mixed Http::response()、Http::sequence() 或接收請求的 closure */
    private mixed $erpNext = null;

    private function fakeErp(mixed $next): void
    {
        $this->erpNext = $next;
    }

    private function event(int $aggregateId = 1, string $type = 'reservation.created', array $attributes = []): OutboxEvent
    {
        return OutboxEvent::create($attributes + [
            'aggregate_type' => 'reservation', 'aggregate_id' => $aggregateId, 'store_id' => $this->store->id,
            'event_type' => $type, 'payload' => ['reservation' => ['code' => 'ABCD2345']],
            'status' => OutboxStatus::Pending, 'attempts' => 0, 'next_attempt_at' => now(),
        ]);
    }

    private function dispatchOnce(): array
    {
        return app(OutboxDispatcher::class)->run(5);
    }

    private function advance(int $seconds): void
    {
        Carbon::setTestNow(now()->addSeconds($seconds));
    }

    // ── 成功 ────────────────────────────────────────────────────────────

    public function test_a_pending_event_is_sent_to_the_erp_with_an_idempotency_key(): void
    {
        $this->fakeErp(Http::response(['message' => 'Event applied']));
        $event = $this->event();

        $this->assertSame(['sent' => 1, 'retrying' => 0, 'failed' => 0], $this->dispatchOnce());

        Http::assertSent(fn ($request) => $request->url() === self::URL
            && $request->method() === 'POST'
            && $request->header('Idempotency-Key') === ["outbox-{$event->id}"]
            && $request['event_type'] === 'reservation.created'
            && $request['aggregate_id'] === '1'
            && $request['sequence'] === $event->id
            && $request['data']['reservation']['code'] === 'ABCD2345');

        $event->refresh();
        $this->assertSame(OutboxStatus::Sent, $event->status);
        $this->assertNotNull($event->sent_at);
        $this->assertNull($event->last_error);
        $this->assertSame(1, $event->attempts);
    }

    public function test_sent_events_are_never_sent_again(): void
    {
        $this->fakeErp(Http::response('ok'));
        $this->event();

        $this->dispatchOnce();
        $this->advance(3600);
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0], $this->dispatchOnce());

        Http::assertSentCount(1);
    }

    public function test_events_not_yet_due_are_left_alone(): void
    {
        Http::fake();
        $this->event(attributes: ['next_attempt_at' => now()->addMinutes(5)]);

        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0], $this->dispatchOnce());
        Http::assertNothingSent();
    }

    // ── 重試與退避 ──────────────────────────────────────────────────────

    public function test_a_temporary_failure_is_retried_later_with_a_growing_delay(): void
    {
        $this->fakeErp(Http::response('boom', 503));
        $event = $this->event();

        $delays = [];
        foreach (range(1, 4) as $attempt) {
            $this->assertSame(['sent' => 0, 'retrying' => 1, 'failed' => 0], $this->dispatchOnce());

            $event->refresh();
            $this->assertSame(OutboxStatus::Pending, $event->status);
            $this->assertSame($attempt, $event->attempts);
            $this->assertStringContainsString('HTTP 503', $event->last_error);
            $delays[] = (int) now()->diffInSeconds($event->next_attempt_at, false);

            $this->advance($delays[array_key_last($delays)] + 1);
        }

        // 基準 60 秒、每次加倍（±20% 抖動）：60、120、240、480
        foreach ([60, 120, 240, 480] as $i => $expected) {
            $this->assertEqualsWithDelta($expected, $delays[$i], $expected * 0.21, "第 ".($i + 1).' 次失敗後的等待時間');
        }
    }

    public function test_it_does_not_retry_before_the_backoff_has_elapsed(): void
    {
        $this->fakeErp(Http::response('boom', 503));
        $this->event();

        $this->dispatchOnce();
        $this->advance(30); // 還沒到 60 秒 ± 20%
        $this->dispatchOnce();

        Http::assertSentCount(1);
    }

    public function test_the_backoff_has_jitter_so_retries_do_not_arrive_in_lockstep(): void
    {
        $dispatcher = app(OutboxDispatcher::class);

        $values = array_map(fn () => $dispatcher->backoffSeconds(3), range(1, 30));

        $this->assertGreaterThan(5, count(array_unique($values)));
        $this->assertGreaterThanOrEqual(192, min($values)); // 240 × 0.8
        $this->assertLessThanOrEqual(288, max($values));    // 240 × 1.2
    }

    public function test_after_the_maximum_attempts_the_event_moves_to_the_failed_list(): void
    {
        $this->fakeErp(Http::response('down', 503));
        $event = $this->event();

        $results = [];
        foreach (range(1, 6) as $i) {
            $results[] = $this->dispatchOnce();
            $this->advance(3600);
        }

        $this->assertSame(['retrying', 'retrying', 'retrying', 'retrying', 'retrying', 'failed'], array_map(
            fn ($r) => $r['failed'] ? 'failed' : 'retrying', $results,
        ));

        $event->refresh();
        $this->assertSame(OutboxStatus::Failed, $event->status);
        $this->assertSame(6, $event->attempts);
        $this->assertNull($event->next_attempt_at);
        $this->assertStringContainsString('503', $event->last_error);

        // 進入失敗清單後，不再被自動撿走
        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 0], $this->dispatchOnce());
        Http::assertSentCount(6);
    }

    public function test_a_permanent_rejection_goes_straight_to_the_failed_list_without_retrying(): void
    {
        $this->fakeErp(Http::response(['error' => 'bad data'], 422));
        $event = $this->event();

        $this->assertSame(['sent' => 0, 'retrying' => 0, 'failed' => 1], $this->dispatchOnce());

        $event->refresh();
        $this->assertSame(OutboxStatus::Failed, $event->status);
        $this->assertSame(1, $event->attempts, '重試也不會好的錯誤不浪費重試次數');
        $this->assertStringContainsString('HTTP 422', $event->last_error);
    }

    public function test_which_http_statuses_are_retried(): void
    {
        foreach ([408 => 'retrying', 429 => 'retrying', 500 => 'retrying', 502 => 'retrying', 504 => 'retrying',
            400 => 'failed', 401 => 'failed', 404 => 'failed', 422 => 'failed'] as $status => $expected) {
            OutboxEvent::query()->delete();
            $this->fakeErp(Http::response('x', $status));
            $this->event();

            $result = $this->dispatchOnce();

            $this->assertSame(1, $result[$expected], "HTTP {$status} 應該是 {$expected}");
        }
    }

    public function test_a_connection_failure_is_a_temporary_error(): void
    {
        $this->fakeErp(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));
        $event = $this->event();

        $this->assertSame(1, $this->dispatchOnce()['retrying']);
        $this->assertStringContainsString('無法連線', $event->fresh()->last_error);
    }

    public function test_an_unexpected_exception_does_not_stop_the_rest_of_the_batch(): void
    {
        $this->app->instance(ErpGateway::class, new class implements ErpGateway
        {
            public function send(OutboxEvent $event): void
            {
                if ($event->aggregate_id === 1) {
                    throw new \RuntimeException('bug in our code');
                }
            }
        });
        $broken = $this->event(1);
        $fine = $this->event(2);

        $result = app(OutboxDispatcher::class)->run(5);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['retrying']);
        $this->assertSame(OutboxStatus::Sent, $fine->fresh()->status);
        $this->assertStringContainsString('bug in our code', $broken->fresh()->last_error);
    }

    // ── 冪等：回應遺失 ──────────────────────────────────────────────────

    public function test_a_resend_after_a_lost_response_carries_the_same_idempotency_key(): void
    {
        // 第一次：ERP 其實處理了，但回應遺失（504）；第二次：重送，ERP 靠同一個鍵去重
        $this->fakeErp(Http::sequence()->push('lost', 504)->push(['message' => 'ok', 'duplicate' => true]));
        $event = $this->event();

        $this->dispatchOnce();
        $this->advance(200);
        $this->dispatchOnce();

        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique()->all();
        $this->assertSame(["outbox-{$event->id}"], $keys, '重送必須帶同一個鍵，ERP 才分辨得出是同一筆');
        $this->assertSame(OutboxStatus::Sent, $event->fresh()->status);
    }

    public function test_the_key_is_stable_per_event(): void
    {
        $a = $this->event(1);
        $b = $this->event(2);

        $this->assertSame("outbox-{$a->id}", HttpErpGateway::idempotencyKey($a));
        $this->assertNotSame(HttpErpGateway::idempotencyKey($a), HttpErpGateway::idempotencyKey($b));
    }

    // ── 順序 ────────────────────────────────────────────────────────────

    public function test_events_of_the_same_reservation_are_sent_in_order_even_when_both_are_due(): void
    {
        $this->fakeErp(Http::response('ok'));
        $created = $this->event(1, 'reservation.created');
        $cancelled = $this->event(1, 'reservation.status_changed');

        $this->dispatchOnce();

        $order = collect(Http::recorded())->map(fn ($pair) => $pair[0]['event_type'])->all();
        $this->assertSame(['reservation.created', 'reservation.status_changed'], $order);
        $this->assertTrue($created->fresh()->sent_at <= $cancelled->fresh()->sent_at);
    }

    public function test_a_later_event_waits_while_an_earlier_one_for_the_same_reservation_is_failing(): void
    {
        $this->fakeErp(Http::response('down', 503));
        $this->event(1, 'reservation.created');
        $later = $this->event(1, 'reservation.status_changed');

        $this->dispatchOnce();
        $this->advance(100000);
        $this->dispatchOnce();

        // 「建立」一直送不出去，「取消」就不能越過它先送：否則 ERP 會先看到取消、再看到建立
        $this->assertSame(0, $later->fresh()->attempts);
        $this->assertTrue(collect(Http::recorded())->every(fn ($pair) => $pair[0]['event_type'] === 'reservation.created'));
    }

    public function test_a_failed_event_blocks_later_events_until_it_is_dealt_with(): void
    {
        $this->fakeErp(Http::response('bad', 422));
        $first = $this->event(1, 'reservation.created');
        $second = $this->event(1, 'reservation.status_changed');

        $this->dispatchOnce();
        $this->assertSame(OutboxStatus::Failed, $first->fresh()->status);

        $this->fakeErp(Http::response('ok'));
        $this->advance(60);
        $this->dispatchOnce();
        $this->assertSame(OutboxStatus::Pending, $second->fresh()->status, '前一筆還在失敗清單裡，後面的繼續等');

        // 人工處理（重送）之後恢復
        // （用 fresh()：$first 是建立時的舊實例，它記得的狀態本來就是 pending，Eloquent 會判定「沒有變化」而不寫入）
        $first->fresh()->update(['status' => OutboxStatus::Pending, 'attempts' => 0, 'next_attempt_at' => now()]);
        $this->dispatchOnce();
        $this->assertSame(OutboxStatus::Sent, $second->fresh()->status);
    }

    public function test_different_reservations_do_not_block_each_other(): void
    {
        $this->fakeErp(fn ($request) => $request['aggregate_id'] === '1' ? Http::response('down', 503) : Http::response('ok'));
        $this->event(1);
        $healthy = $this->event(2);

        $this->dispatchOnce();

        $this->assertSame(OutboxStatus::Sent, $healthy->fresh()->status);
    }

    public function test_one_run_drains_a_chain_of_events_of_the_same_reservation(): void
    {
        $this->fakeErp(Http::response('ok'));
        foreach (['reservation.created', 'reservation.status_changed', 'reservation.status_changed'] as $type) {
            $this->event(1, $type);
        }

        $this->assertSame(3, $this->dispatchOnce()['sent'], '不必每筆等一分鐘');
    }

    // ── 領取（多個 worker）─────────────────────────────────────────────

    public function test_a_claimed_event_is_leased_so_another_worker_does_not_take_it(): void
    {
        $event = $this->event();

        // 模擬 worker A 領走了、還在送：領取會把 next_attempt_at 往後推一個租約並累計次數
        $claim = new \ReflectionMethod(OutboxDispatcher::class, 'claim');
        $first = $claim->invoke(app(OutboxDispatcher::class), 10);
        $second = $claim->invoke(app(OutboxDispatcher::class), 10);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second, '同一筆不能被兩個 worker 同時領走');
        $this->assertSame(1, $event->fresh()->attempts);
        $this->assertEquals(now()->addSeconds(120), $event->fresh()->next_attempt_at);
    }

    public function test_an_event_whose_worker_crashed_is_picked_up_again_after_the_lease_expires(): void
    {
        $this->fakeErp(Http::response('ok'));
        $event = $this->event();
        $claim = new \ReflectionMethod(OutboxDispatcher::class, 'claim');
        $claim->invoke(app(OutboxDispatcher::class), 10); // 領走之後 worker 當掉，什麼都沒送

        $this->advance(60);
        $this->assertSame(0, $this->dispatchOnce()['sent'], '租約還沒到期');

        $this->advance(120);
        $this->assertSame(1, $this->dispatchOnce()['sent']);
        $this->assertSame(2, $event->fresh()->attempts, '當掉的那次也算一次嘗試，不會無限重試');
    }

    public function test_the_claim_query_uses_skip_locked(): void
    {
        DB::enableQueryLog();
        $this->event();
        $claim = new \ReflectionMethod(OutboxDispatcher::class, 'claim');
        $claim->invoke(app(OutboxDispatcher::class), 10);

        $sql = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'for update'));

        $this->assertNotNull($sql);
        $this->assertStringContainsString('skip locked', $sql);
    }

    // ── 手動重送不會讓成功的事件重複送 ──────────────────────────────────

    public function test_a_success_is_not_overwritten_by_a_late_failure_result(): void
    {
        // 條件式更新：只有「還是 pending」才會被改狀態
        $event = $this->event();
        $event->update(['status' => OutboxStatus::Sent, 'sent_at' => now()]);

        $this->app->instance(ErpGateway::class, new class implements ErpGateway
        {
            public function send(OutboxEvent $event): void
            {
                throw new ErpTemporaryException('late failure');
            }
        });
        $deliver = new \ReflectionMethod(OutboxDispatcher::class, 'deliver');
        $deliver->invoke(app(OutboxDispatcher::class), $event->fresh());

        $this->assertSame(OutboxStatus::Sent, $event->fresh()->status);
    }

    public function test_the_gateway_classifies_errors_by_exception_type(): void
    {
        $this->fakeErp(Http::response('x', 503));
        try {
            app(ErpGateway::class)->send($this->event());
            $this->fail();
        } catch (ErpTemporaryException) {
            $this->addToAssertionCount(1);
        }

        $this->fakeErp(Http::response('x', 400));
        $this->expectException(ErpPermanentException::class);
        app(ErpGateway::class)->send($this->event(2));
    }
}
