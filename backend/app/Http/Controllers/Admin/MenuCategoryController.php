<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuCategoryRequest;
use App\Http\Requests\Admin\UpdateMenuCategoryRequest;
use App\Http\Resources\MenuCategoryResource;
use App\Models\MenuCategory;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MenuCategoryController extends Controller
{
    public function index(Store $store): AnonymousResourceCollection
    {
        Gate::authorize('view', $store);

        return MenuCategoryResource::collection(
            $store->menuCategories()->withCount('items')->orderBy('sort_order')->orderBy('id')->get()
        );
    }

    public function store(StoreMenuCategoryRequest $request, Store $store): JsonResponse
    {
        $category = $store->menuCategories()->create($request->validated());

        return (new MenuCategoryResource($category->refresh()->loadCount('items')))->response()->setStatusCode(201);
    }

    public function update(UpdateMenuCategoryRequest $request, Store $store, MenuCategory $menuCategory): MenuCategoryResource
    {
        $menuCategory->update($request->validated());

        return new MenuCategoryResource($menuCategory->loadCount('items'));
    }

    /** 刪除分類不會刪掉品項：資料庫外鍵設為 nullOnDelete，底下的品項回到「未分類」。 */
    public function destroy(Store $store, MenuCategory $menuCategory): JsonResponse
    {
        Gate::authorize('manageSettings', $store);

        $menuCategory->delete();

        return response()->json(['message' => '已刪除分類，底下的品項改為未分類']);
    }
}
