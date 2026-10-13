<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OutboxStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\OutboxEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class OutboxEventController extends Controller
{
    /** 各狀態的數量與事件列表（預設看失敗清單）。 */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', OutboxEvent::class);

        $request->validate(['status' => ['sometimes', 'in:pending,sent,failed'], 'page' => ['sometimes', 'integer', 'min:1']]);

        $scoped = fn () => OutboxEvent::query()->when(
            $request->user()->role !== UserRole::HqAdmin,
            fn ($q) => $q->where('store_id', $request->user()->store_id),
        );

        $counts = $scoped()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $events = $scoped()
            ->where('status', $request->query('status', OutboxStatus::Failed->value))
            // 最新的在前；失敗清單最需要看的是最近壞掉的
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (OutboxEvent $e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'aggregate_id' => $e->aggregate_id,
                'store_id' => $e->store_id,
                'reservation_code' => $e->payload['reservation']['code'] ?? null,
                'status' => $e->status->value,
                'attempts' => $e->attempts,
                'max_attempts' => (int) config('erp.max_attempts'),
                'next_attempt_at' => $e->next_attempt_at?->toIso8601String(),
                'last_error' => $e->last_error,
                'sent_at' => $e->sent_at?->toIso8601String(),
                'created_at' => $e->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                'sent' => (int) ($counts['sent'] ?? 0),
            ],
            'events' => $events->items(),
            'meta' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'total' => $events->total(), 'per_page' => $events->perPage()],
        ]);
    }

    /**
     * 手動重送：把失敗（或還在等退避的）事件改回「立刻可送」，由排程的 worker 撿走。
     *
     * 不在這裡直接呼叫 ERP：請求中同步打外部系統，ERP 一慢整個後台就卡住；也讓「重送」和「自動重試」走同一條路徑。
     * 嘗試次數歸零，否則事件已經用掉重試額度，下一次失敗就會立刻再回到失敗清單。
     */
    public function retry(OutboxEvent $outboxEvent): JsonResponse
    {
        Gate::authorize('retry', $outboxEvent);

        $affected = DB::table('outbox_events')
            ->where('id', $outboxEvent->id)
            // 條件式更新：只有「失敗」或「等重試」才能重送；已經送成功的不可以被改回待送，否則會重複通知 ERP
            ->where('status', '!=', OutboxStatus::Sent->value)
            ->update(['status' => OutboxStatus::Pending->value, 'attempts' => 0, 'next_attempt_at' => now(), 'updated_at' => now()]);

        if ($affected === 0) {
            throw new ApiException('這筆事件已經成功送出，不需要重送', 'ALREADY_SENT', 409);
        }

        Log::channel('erp')->info('outbox event manually re-queued', ['event_id' => $outboxEvent->id, 'by_user_id' => request()->user()->id]);

        return response()->json(['message' => '已排入重送，約一分鐘內會送出']);
    }
}
