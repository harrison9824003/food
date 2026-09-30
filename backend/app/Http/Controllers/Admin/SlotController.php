<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SlotIndexRequest;
use App\Http\Requests\Admin\UpdateSlotRequest;
use App\Http\Resources\SlotResource;
use App\Models\Slot;
use App\Models\Store;
use App\Services\SlotGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SlotController extends Controller
{
    public function index(SlotIndexRequest $request, Store $store): AnonymousResourceCollection
    {
        return SlotResource::collection(
            $store->slots()->where('slot_date', $request->validated('date'))->orderBy('start_time')->get()
        );
    }

    public function update(UpdateSlotRequest $request, Store $store, Slot $slot): SlotResource
    {
        $capacity = $request->validated('capacity');

        if ($capacity === $slot->capacity) {
            return new SlotResource($slot);
        }

        // 不能「先讀 booked、判斷後再寫入」：讀到寫入之間可能剛好有人訂位，導致 capacity < booked。
        // 因此把判斷放進 UPDATE 的 WHERE，與訂位端的條件式更新是同一種手法，以 affected rows 判斷成敗。
        // （已排除新值等於舊值的情況；否則 MySQL 對「值沒變」回報 0 列，會被誤判為衝突。）
        $affected = Slot::query()
            ->whereKey($slot->id)
            ->where('booked', '<=', $capacity)
            ->update(['capacity' => $capacity, 'updated_at' => now()]);

        if ($affected === 0) {
            $booked = $slot->fresh()->booked;

            throw new ApiException(
                "名額不可低於已訂人數（目前已訂 {$booked} 位）",
                'CAPACITY_BELOW_BOOKED',
                409,
            );
        }

        return new SlotResource($slot->refresh());
    }

    public function generate(Store $store, SlotGenerator $slots): JsonResponse
    {
        Gate::authorize('manageSettings', $store);

        return response()->json($slots->sync($store));
    }
}
