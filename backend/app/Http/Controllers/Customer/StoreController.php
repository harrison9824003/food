<?php

namespace App\Http\Controllers\Customer;

use App\Booking\AvailabilityService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AvailabilityRequest;
use App\Http\Resources\MenuItemPublicResource;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class StoreController extends Controller
{
    /** 啟用中的門市（顧客選門市用）。 */
    public function index(): JsonResponse
    {
        $stores = Store::query()->where('is_active', true)->orderBy('id')
            ->get(['id', 'name', 'address', 'phone', 'deposit_per_person', 'advance_days']);

        return response()->json($stores);
    }

    public function calendar(Store $store, AvailabilityService $availability): JsonResponse
    {
        $this->ensureActive($store);

        return response()->json($availability->calendar($store));
    }

    public function availability(AvailabilityRequest $request, Store $store, AvailabilityService $availability): JsonResponse
    {
        $this->ensureActive($store);

        return response()->json([
            'date' => $request->validated('date'),
            'party_size' => (int) $request->validated('party_size'),
            'slots' => $availability->forDate($store, $request->validated('date'), (int) $request->validated('party_size')),
        ]);
    }

    /**
     * 供訂位時預點的菜單，依分類分組。
     *
     * 下架的品項、停用分類底下的品項都不出現；沒有分類的品項歸在最後的「其他」。
     * 暫時售完的品項仍會列出（標示今日售完），但不可預點（訂位時伺服器會再檢查）。
     */
    public function menu(Store $store): JsonResponse
    {
        $this->ensureActive($store);

        $categories = $store->menuCategories()->orderBy('sort_order')->orderBy('id')->get();
        $items = $store->menuItems()->where('is_active', true)->with('images')
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy(fn ($item) => $item->category_id ?? 0);

        $groups = [];
        foreach ($categories as $category) {
            $inCategory = $items->get($category->id);

            if ($category->is_active && $inCategory?->isNotEmpty()) {
                $groups[] = ['id' => $category->id, 'name' => $category->name, 'items' => MenuItemPublicResource::collection($inCategory)->resolve()];
            }
        }

        if ($items->get(0)?->isNotEmpty()) {
            $groups[] = ['id' => null, 'name' => '其他', 'items' => MenuItemPublicResource::collection($items->get(0))->resolve()];
        }

        return response()->json(['categories' => $groups]);
    }

    /** 未啟用的門市對顧客來說等同不存在。 */
    private function ensureActive(Store $store): void
    {
        if (! $store->is_active) {
            throw new ApiException('找不到門市', 'NOT_FOUND', 404);
        }
    }
}
