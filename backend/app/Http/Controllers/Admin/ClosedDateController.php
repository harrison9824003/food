<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreClosedDateRequest;
use App\Http\Resources\ClosedDateResource;
use App\Models\Store;
use App\Models\StoreClosedDate;
use App\Services\ClosedDateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ClosedDateController extends Controller
{
    /** 只列出今天以後的公休日；過去的已無操作意義。 */
    public function index(Store $store): AnonymousResourceCollection
    {
        Gate::authorize('view', $store);

        return ClosedDateResource::collection(
            $store->closedDates()->where('closed_date', '>=', today()->toDateString())->orderBy('closed_date')->get()
        );
    }

    public function store(StoreClosedDateRequest $request, Store $store, ClosedDateService $service): JsonResponse
    {
        $closed = $service->close($store, $request->validated('closed_date'), $request->validated('reason'));

        return (new ClosedDateResource($closed))->response()->setStatusCode(201);
    }

    public function destroy(Store $store, StoreClosedDate $closedDate, ClosedDateService $service): JsonResponse
    {
        Gate::authorize('manageSettings', $store);

        $service->reopen($store, $closedDate);

        return response()->json(['message' => '已取消公休']);
    }
}
