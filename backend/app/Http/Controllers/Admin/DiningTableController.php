<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDiningTableRequest;
use App\Http\Requests\Admin\UpdateDiningTableRequest;
use App\Http\Resources\DiningTableResource;
use App\Models\DiningTable;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DiningTableController extends Controller
{
    public function index(Store $store): AnonymousResourceCollection
    {
        Gate::authorize('view', $store);

        return DiningTableResource::collection($store->diningTables()->orderBy('code')->get());
    }

    public function store(StoreDiningTableRequest $request, Store $store): JsonResponse
    {
        $table = $store->diningTables()->create($request->validated());

        return (new DiningTableResource($table->refresh()))->response()->setStatusCode(201);
    }

    /**
     * 不提供刪除：桌位會被歷史訂位參照，停用（is_active=false）即可從新訂位中排除。
     */
    public function update(UpdateDiningTableRequest $request, Store $store, DiningTable $diningTable): DiningTableResource
    {
        $diningTable->update($request->validated());

        return new DiningTableResource($diningTable);
    }
}
