<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateStoreRequest;
use App\Http\Requests\Admin\UpdateStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\SlotGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StoreController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Store::class);

        $actor = $request->user();

        // 總部看全部；店長、店員只看得到自己的門市
        $stores = Store::query()
            ->when($actor->role !== UserRole::HqAdmin, fn ($q) => $q->whereKey($actor->store_id))
            ->orderBy('id')
            ->get();

        return StoreResource::collection($stores);
    }

    public function show(Store $store): StoreResource
    {
        Gate::authorize('view', $store);

        return new StoreResource($store);
    }

    public function store(CreateStoreRequest $request): JsonResponse
    {
        $store = Store::create($request->validated());

        return (new StoreResource($store))->response()->setStatusCode(201);
    }

    public function update(UpdateStoreRequest $request, Store $store, SlotGenerator $slots): StoreResource
    {
        $data = $request->validated();

        // 名稱、訂金、停用只有總部能改。明確回 403，而不是默默忽略欄位讓店長以為改成功了
        if (array_intersect_key($data, array_flip(Store::HQ_ONLY_FIELDS)) !== []) {
            Gate::authorize('updateCore', $store);
        }

        $store->fill($data);
        $windowChanged = $store->isDirty(['advance_days', 'is_active']);
        $store->save();

        // 開放天數變長（或重新啟用）時要立即補上新的時段，不必等隔天排程
        if ($windowChanged) {
            $slots->sync($store);
        }

        return new StoreResource($store);
    }
}
