<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RefundStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Refunds\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RefundController extends Controller
{
    private const REASON_LABELS = [
        'CUSTOMER_CANCEL' => '顧客取消',
        'STORE_CANCEL' => '門市取消',
        'LATE_PAYMENT_SLOT_TAKEN' => '逾時後才付款，座位已被訂走',
        'DUPLICATE_PAYMENT' => '重複付款',
        'PAID_AFTER_CANCEL' => '取消後才付款',
        'UNEXPECTED_STATE' => '訂位狀態異常',
    ];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Refund::class);

        $request->validate(['status' => ['sometimes', 'in:requested,approved,failed,completed,rejected']]);

        $scoped = fn () => Refund::query()->when(
            $request->user()->role !== UserRole::HqAdmin,
            fn ($q) => $q->where('store_id', $request->user()->store_id),
        );

        $counts = $scoped()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $refunds = $scoped()
            ->with(['reservation.slot', 'payment', 'requester', 'approver'])
            // 預設看「待簽核」：這是主管最需要處理的
            ->where('status', $request->query('status', RefundStatus::Requested->value))
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (Refund $r) => [
                'id' => $r->id,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'amount' => $r->amount,
                'reason' => $r->reason,
                'reason_label' => self::REASON_LABELS[$r->reason] ?? $r->reason,
                'store_id' => $r->store_id,
                'reservation_code' => $r->reservation->code,
                'customer_name' => $r->reservation->customer_name,
                'dining_at' => $r->reservation->slot->slot_date->toDateString().' '.substr($r->reservation->slot->start_time, 0, 5),
                'merchant_trade_no' => $r->payment->merchant_trade_no,
                'requested_by' => match ($r->requested_by_type) {
                    'customer' => '顧客',
                    'system' => '系統（付款異常）',
                    default => $r->requester?->name ?? '後台人員',
                },
                // 申請人是登入者本人時，前端據此隱藏「核准」（後端也會擋）
                'requested_by_me' => $r->requested_by_type === 'staff' && $r->requested_by_id === $request->user()->id,
                'decided_by' => $r->approver?->name,
                'decided_at' => $r->approved_at?->toIso8601String(),
                'reject_reason' => $r->reject_reason,
                'failure_reason' => $r->failure_reason,
                'attempts' => $r->attempts,
                'next_attempt_at' => $r->next_attempt_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'counts' => collect(['requested', 'approved', 'failed', 'completed', 'rejected'])
                ->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
            'refunds' => $refunds->items(),
            'meta' => ['current_page' => $refunds->currentPage(), 'last_page' => $refunds->lastPage(), 'total' => $refunds->total(), 'per_page' => $refunds->perPage()],
        ]);
    }

    public function approve(Request $request, Refund $refund, RefundService $service): JsonResponse
    {
        Gate::authorize('decide', $refund);

        $result = $service->approve($refund->id, $request->user());

        return response()->json(['status' => $result->status->value, 'message' => '已核准，退款處理中']);
    }

    public function reject(Request $request, Refund $refund, RefundService $service): JsonResponse
    {
        Gate::authorize('decide', $refund);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $result = $service->reject($refund->id, $request->user(), $data['reason']);

        return response()->json(['status' => $result->status->value, 'message' => '已駁回']);
    }

    /** 失敗的退款人工重試。呼叫前應先到金流商後台確認這筆確實沒退成功。 */
    public function retry(Request $request, Refund $refund, RefundService $service): JsonResponse
    {
        Gate::authorize('decide', $refund);

        $result = $service->retry($refund->id, $request->user());

        return response()->json(['status' => $result->status->value, 'message' => '已重新排入退款']);
    }
}
