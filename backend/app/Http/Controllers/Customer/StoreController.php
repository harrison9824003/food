<?php

namespace App\Http\Controllers\Customer;

use App\Booking\AvailabilityService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\AvailabilityRequest;
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

    /** 未啟用的門市對顧客來說等同不存在。 */
    private function ensureActive(Store $store): void
    {
        if (! $store->is_active) {
            throw new ApiException('找不到門市', 'NOT_FOUND', 404);
        }
    }
}
