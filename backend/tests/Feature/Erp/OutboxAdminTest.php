<?php

namespace Tests\Feature\Erp;

use App\Enums\OutboxStatus;
use App\Models\OutboxEvent;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 後台「同步失敗清單」與手動重送。
 */
class OutboxAdminTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Carbon::setTestNow('2026-10-05 10:00:00');
        $this->storeA = Store::factory()->create();
        $this->storeB = Store::factory()->create();
    }

    private function event(Store $store, OutboxStatus $status, string $code = 'AAAA1111', array $attributes = []): OutboxEvent
    {
        static $aggregate = 0;

        return OutboxEvent::create($attributes + [
            'aggregate_type' => 'reservation', 'aggregate_id' => ++$aggregate, 'store_id' => $store->id,
            'event_type' => 'reservation.created', 'payload' => ['reservation' => ['code' => $code]],
            'status' => $status, 'attempts' => $status === OutboxStatus::Failed ? 6 : 0,
            'next_attempt_at' => $status === OutboxStatus::Pending ? now() : null,
            'last_error' => $status === OutboxStatus::Failed ? 'ERP 暫時無法處理（HTTP 503）' : null,
            'sent_at' => $status === OutboxStatus::Sent ? now() : null,
        ]);
    }

    private function loginAs(User $user): void
    {
        Sanctum::actingAs($user);
    }

    // ── 清單 ────────────────────────────────────────────────────────────

    public function test_the_failed_list_is_the_default_view_and_shows_counts_for_every_status(): void
    {
        $this->event($this->storeA, OutboxStatus::Failed, 'FAIL0001');
        $this->event($this->storeA, OutboxStatus::Pending);
        $this->event($this->storeA, OutboxStatus::Pending);
        $this->event($this->storeA, OutboxStatus::Sent);
        $this->loginAs(User::factory()->hqAdmin()->create());

        $response = $this->getJson('/api/admin/outbox-events')->assertOk()
            ->assertJsonPath('counts', ['pending' => 2, 'failed' => 1, 'sent' => 1])
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.reservation_code', 'FAIL0001')
            ->assertJsonPath('events.0.status', 'failed')
            ->assertJsonPath('events.0.attempts', 6)
            ->assertJsonPath('events.0.max_attempts', 6)
            ->assertJsonPath('events.0.last_error', 'ERP 暫時無法處理（HTTP 503）');

        $this->assertArrayNotHasKey('payload', $response->json('events.0'), '清單不外洩顧客個資（事件內容含手機號碼）');
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $this->event($this->storeA, OutboxStatus::Failed);
        $this->event($this->storeA, OutboxStatus::Pending);
        $this->event($this->storeA, OutboxStatus::Sent);
        $this->loginAs(User::factory()->hqAdmin()->create());

        foreach (['pending', 'sent', 'failed'] as $status) {
            $this->getJson("/api/admin/outbox-events?status={$status}")->assertOk()
                ->assertJsonCount(1, 'events')->assertJsonPath('events.0.status', $status);
        }
        $this->getJson('/api/admin/outbox-events?status=bogus')->assertStatus(422);
    }

    public function test_newest_failures_come_first(): void
    {
        $old = $this->event($this->storeA, OutboxStatus::Failed, 'OLD00001');
        $new = $this->event($this->storeA, OutboxStatus::Failed, 'NEW00001');
        $this->loginAs(User::factory()->hqAdmin()->create());

        $this->getJson('/api/admin/outbox-events')->assertOk()
            ->assertJsonPath('events.0.id', $new->id)->assertJsonPath('events.1.id', $old->id);
    }

    // ── 權限 ────────────────────────────────────────────────────────────

    public function test_hq_sees_every_store_and_a_manager_only_their_own(): void
    {
        $this->event($this->storeA, OutboxStatus::Failed);
        $this->event($this->storeB, OutboxStatus::Failed);

        $this->loginAs(User::factory()->hqAdmin()->create());
        $this->getJson('/api/admin/outbox-events')->assertOk()->assertJsonCount(2, 'events')->assertJsonPath('counts.failed', 2);

        $this->loginAs(User::factory()->storeManager($this->storeA)->create());
        $this->getJson('/api/admin/outbox-events')->assertOk()->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.store_id', $this->storeA->id)
            ->assertJsonPath('counts.failed', 1);
    }

    public function test_staff_cannot_see_or_retry_events(): void
    {
        $event = $this->event($this->storeA, OutboxStatus::Failed);
        $this->loginAs(User::factory()->staff($this->storeA)->create());

        $this->getJson('/api/admin/outbox-events')->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        $this->postJson("/api/admin/outbox-events/{$event->id}/retry")->assertStatus(403);
        $this->assertSame(OutboxStatus::Failed, $event->fresh()->status);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/admin/outbox-events')->assertStatus(401);
    }

    // ── 手動重送 ────────────────────────────────────────────────────────

    public function test_retrying_a_failed_event_makes_it_due_again_with_a_fresh_attempt_budget(): void
    {
        $event = $this->event($this->storeA, OutboxStatus::Failed);
        $this->loginAs(User::factory()->storeManager($this->storeA)->create());

        $this->postJson("/api/admin/outbox-events/{$event->id}/retry")->assertOk();

        $event->refresh();
        $this->assertSame(OutboxStatus::Pending, $event->status);
        $this->assertSame(0, $event->attempts, '重送要有完整的重試額度，否則下一次失敗就立刻再回到失敗清單');
        $this->assertEquals(now(), $event->next_attempt_at);
    }

    public function test_a_retried_event_is_delivered_by_the_next_dispatch(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $event = $this->event($this->storeA, OutboxStatus::Failed);
        $this->loginAs(User::factory()->hqAdmin()->create());

        $this->postJson("/api/admin/outbox-events/{$event->id}/retry")->assertOk();
        $this->artisan('outbox:dispatch', ['--budget' => 5])->assertSuccessful();

        $this->assertSame(OutboxStatus::Sent, $event->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_an_event_waiting_for_its_backoff_can_be_retried_immediately(): void
    {
        $event = $this->event($this->storeA, OutboxStatus::Pending, attributes: ['attempts' => 2, 'next_attempt_at' => now()->addMinutes(10)]);
        $this->loginAs(User::factory()->hqAdmin()->create());

        $this->postJson("/api/admin/outbox-events/{$event->id}/retry")->assertOk();

        $this->assertEquals(now(), $event->fresh()->next_attempt_at);
    }

    public function test_an_event_that_was_already_sent_cannot_be_requeued(): void
    {
        $event = $this->event($this->storeA, OutboxStatus::Sent);
        $this->loginAs(User::factory()->hqAdmin()->create());

        // 改回待送會讓 ERP 收到第二次通知
        $this->postJson("/api/admin/outbox-events/{$event->id}/retry")
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_SENT');
        $this->assertSame(OutboxStatus::Sent, $event->fresh()->status);
    }

    public function test_a_manager_cannot_retry_another_stores_event(): void
    {
        $foreign = $this->event($this->storeB, OutboxStatus::Failed);
        $this->loginAs(User::factory()->storeManager($this->storeA)->create());

        $this->postJson("/api/admin/outbox-events/{$foreign->id}/retry")->assertStatus(403);
        $this->assertSame(OutboxStatus::Failed, $foreign->fresh()->status);
    }

    public function test_retrying_an_unknown_event_is_a_404(): void
    {
        $this->loginAs(User::factory()->hqAdmin()->create());

        $this->postJson('/api/admin/outbox-events/999999/retry')->assertStatus(404);
    }

    // ── 排程 ────────────────────────────────────────────────────────────

    public function test_the_dispatcher_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'outbox:dispatch'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
