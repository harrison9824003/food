<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBusinessHoursRequest;
use App\Models\Store;
use App\Services\SlotGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BusinessHourController extends Controller
{
    public function index(Store $store): JsonResponse
    {
        Gate::authorize('view', $store);

        return response()->json($this->hoursFor($store));
    }

    public function update(UpdateBusinessHoursRequest $request, Store $store, SlotGenerator $slots): JsonResponse
    {
        DB::transaction(function () use ($request, $store) {
            foreach ($request->validated('hours') as $hour) {
                $closed = (bool) $hour['is_closed'];

                $store->businessHours()->updateOrCreate(
                    ['weekday' => $hour['weekday']],
                    [
                        'is_closed' => $closed,
                        'open_time' => $closed ? null : $hour['open_time'],
                        'close_time' => $closed ? null : $hour['close_time'],
                        'slot_minutes' => $closed ? 30 : $hour['slot_minutes'],
                    ],
                );
            }
        });

        // 營業時間改了，立刻讓未來時段跟上（只動沒人訂過的時段，見 SlotGenerator）
        $result = $slots->sync($store);

        return response()->json(['hours' => $this->hoursFor($store), 'slots' => $result]);
    }

    /**
     * 固定回傳週日到週六共 7 筆；尚未設定的日子視為公休，前端不需要自己補洞。
     *
     * @return list<array<string, mixed>>
     */
    private function hoursFor(Store $store): array
    {
        $saved = $store->businessHours()->get()->keyBy('weekday');

        return collect(range(0, 6))->map(function (int $weekday) use ($saved) {
            $hour = $saved->get($weekday);

            return [
                'weekday' => $weekday,
                'is_closed' => $hour?->is_closed ?? true,
                'open_time' => $hour?->open_time ? substr($hour->open_time, 0, 5) : null,
                'close_time' => $hour?->close_time ? substr($hour->close_time, 0, 5) : null,
                'slot_minutes' => $hour?->slot_minutes ?? 30,
            ];
        })->all();
    }
}
