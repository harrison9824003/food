<?php

namespace App\Erp;

use App\Enums\OutboxStatus;
use App\Models\OutboxEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 把 outbox_events 裡待送的事件送去 ERP（at-least-once 投遞）。
 *
 * 「至少一次」的意思：一筆事件可能被送出不只一次（送出去但沒收到回應，我們只能重送），
 * 所以 ERP 端必須靠 Idempotency-Key 去重（見 HttpErpGateway）。反過來說，只要事件還在，就一定會被送出去，不會漏。
 */
class OutboxDispatcher
{
    public function __construct(private readonly ErpGateway $erp) {}

    /**
     * 持續處理直到沒有可送的事件，或超過時間預算。
     *
     * 同一張訂位的事件必須依序送（見 claim），所以一輪只能領到它的一筆；
     * 迴圈讓「建立 → 確認」這種連續事件在同一次排程內就能送完，不必一筆等一分鐘。
     *
     * @param  int  $timeBudgetSeconds  用掉這麼多秒就收工，避免排程每分鐘啟動時上一輪還沒跑完
     * @return array{sent: int, retrying: int, failed: int}
     */
    public function run(int $timeBudgetSeconds = 50): array
    {
        $summary = ['sent' => 0, 'retrying' => 0, 'failed' => 0];
        $deadline = microtime(true) + $timeBudgetSeconds;

        do {
            $events = $this->claim((int) config('erp.batch_size'));

            foreach ($events as $event) {
                $outcome = $this->deliver($event);
                $summary[$outcome]++;
            }
        } while ($events->isNotEmpty() && microtime(true) < $deadline);

        return $summary;
    }

    /**
     * 領取一批可以送的事件。
     *
     * 三個條件缺一不可：
     *  1. 狀態是 pending 且到了下次嘗試時間
     *  2. 同一張訂位「比它早的事件」都已經送成功。否則 ERP 會先收到「取消」再收到「建立」。
     *     早的事件若失敗了、還在等重試或等人處理，後面的就跟著等（寧可慢，不要亂序）
     *  3. 沒有被別的 worker 領走：FOR UPDATE SKIP LOCKED 讓多個 worker 同時跑時，各自拿到不同的列、互不等待
     *
     * 領到之後把 next_attempt_at 往後推一個「租約」時間並累計嘗試次數，然後才離開交易、去呼叫 ERP：
     * 這樣不必在持有資料庫鎖的情況下等 HTTP 回應。worker 若在送到一半時當掉，租約到期後事件會自動被重新領取，
     * 嘗試次數也已經算進去了，不會因為一直當掉而無限重試。
     *
     * @return Collection<int, OutboxEvent>
     */
    private function claim(int $limit): Collection
    {
        return DB::transaction(function () use ($limit) {
            $ids = DB::table('outbox_events')
                ->where('status', OutboxStatus::Pending->value)
                ->where('next_attempt_at', '<=', now())
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('outbox_events as earlier')
                    ->whereColumn('earlier.aggregate_type', 'outbox_events.aggregate_type')
                    ->whereColumn('earlier.aggregate_id', 'outbox_events.aggregate_id')
                    ->whereColumn('earlier.id', '<', 'outbox_events.id')
                    ->where('earlier.status', '!=', OutboxStatus::Sent->value))
                ->orderBy('id')
                ->limit($limit)
                // Laravel 沒有 skipLocked()，要直接給鎖定子句；測試會檢查實際送出的 SQL 真的帶有 skip locked
                ->lock('for update skip locked')
                ->pluck('id');

            if ($ids->isEmpty()) {
                return new Collection;
            }

            DB::table('outbox_events')->whereIn('id', $ids)->update([
                'attempts' => DB::raw('attempts + 1'),
                'next_attempt_at' => now()->addSeconds(config('erp.lease_seconds')),
                'updated_at' => now(),
            ]);

            return OutboxEvent::query()->whereIn('id', $ids)->orderBy('id')->get();
        });
    }

    /** @return 'sent'|'retrying'|'failed' */
    private function deliver(OutboxEvent $event): string
    {
        $log = Log::channel('erp');

        try {
            $this->erp->send($event);
        } catch (ErpPermanentException $e) {
            // 對方明確拒絕：不浪費重試次數，直接交給人處理
            return $this->fail($event, $e->getMessage(), permanent: true);
        } catch (ErpTemporaryException $e) {
            return $this->fail($event, $e->getMessage(), permanent: false);
        } catch (Throwable $e) {
            // 沒預期到的錯誤也不能讓整批卡住：當成暫時性失敗，留在 outbox 裡等重試與日誌排查
            $log->error('outbox unexpected error', ['event_id' => $event->id, 'error' => $e::class.': '.$e->getMessage()]);

            return $this->fail($event, '內部錯誤：'.$e->getMessage(), permanent: false);
        }

        // 條件式更新：只有「還是 pending」才改成已送出。
        // 手動重送與正在進行的投遞可能同時發生，不加條件會互相覆蓋狀態。
        OutboxEvent::query()->whereKey($event->id)->where('status', OutboxStatus::Pending)->update([
            'status' => OutboxStatus::Sent,
            'sent_at' => now(),
            'last_error' => null,
            'next_attempt_at' => null,
        ]);

        $log->info('outbox event sent', ['event_id' => $event->id, 'type' => $event->event_type, 'aggregate_id' => $event->aggregate_id, 'attempts' => $event->attempts]);

        return 'sent';
    }

    /** @return 'retrying'|'failed' */
    private function fail(OutboxEvent $event, string $error, bool $permanent): string
    {
        $log = Log::channel('erp');
        $max = (int) config('erp.max_attempts');
        $giveUp = $permanent || $event->attempts >= $max;

        if ($giveUp) {
            OutboxEvent::query()->whereKey($event->id)->where('status', OutboxStatus::Pending)->update([
                'status' => OutboxStatus::Failed,
                'last_error' => mb_substr($error, 0, 1000),
                'next_attempt_at' => null,
            ]);

            $log->error('outbox event gave up, moved to the failed list', [
                'event_id' => $event->id, 'type' => $event->event_type, 'aggregate_id' => $event->aggregate_id,
                'attempts' => $event->attempts, 'permanent' => $permanent, 'error' => $error,
            ]);

            return 'failed';
        }

        $delay = $this->backoffSeconds($event->attempts);

        OutboxEvent::query()->whereKey($event->id)->where('status', OutboxStatus::Pending)->update([
            'last_error' => mb_substr($error, 0, 1000),
            'next_attempt_at' => now()->addSeconds($delay),
        ]);

        $log->warning('outbox event failed, will retry', [
            'event_id' => $event->id, 'type' => $event->event_type, 'aggregate_id' => $event->aggregate_id,
            'attempts' => $event->attempts, 'retry_in_seconds' => $delay, 'error' => $error,
        ]);

        return 'retrying';
    }

    /**
     * 指數退避：第 n 次失敗後等 base × 2^(n-1) 秒，再加 ±20% 隨機抖動。
     *
     * 抖動的用意：ERP 當機一小時後恢復，累積的幾百筆事件若都在「整數分鐘」同時重試，
     * 會一次把剛恢復的 ERP 壓垮；加了抖動就會散開。
     */
    public function backoffSeconds(int $attempts): int
    {
        $base = (int) config('erp.backoff_base_seconds') * (2 ** max(0, $attempts - 1));
        $jitter = random_int(-20, 20) / 100;

        return max(1, (int) round($base * (1 + $jitter)));
    }
}
