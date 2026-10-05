<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuItemRequest;
use App\Http\Requests\Admin\UpdateMenuItemRequest;
use App\Http\Resources\MenuItemResource;
use App\Models\MenuItem;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MenuItemController extends Controller
{
    public function index(Store $store): AnonymousResourceCollection
    {
        Gate::authorize('view', $store);

        return MenuItemResource::collection(
            $store->menuItems()->with('images')->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    public function store(StoreMenuItemRequest $request, Store $store): JsonResponse
    {
        $item = $store->menuItems()->create($request->validated());

        return (new MenuItemResource($item->refresh()->load('images')))->response()->setStatusCode(201);
    }

    /**
     * 不提供刪除：品項會被歷史訂位參照，下架（is_active=false）即可從新訂位的菜單中隱藏；
     * 「暫時售完」（is_sold_out）則是仍顯示但不可預點。
     */
    public function update(UpdateMenuItemRequest $request, Store $store, MenuItem $menuItem): MenuItemResource
    {
        $menuItem->update($request->validated());

        return new MenuItemResource($menuItem->load('images'));
    }
}
