<?php

namespace App\Http\Controllers\Customer;

use App\Booking\ReservationService;
use App\Booking\ReservationStatusService;
use App\Enums\ReservationStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateReservationRequest;
use App\Http\Requests\Customer\LookupReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    public function store(CreateReservationRequest $request, ReservationService $service): JsonResponse
    {
        // 策略覆蓋標頭只給壓測用；是否生效由 CapacityStrategyResolver 決定
        $reservation = $service->create($request->validated(), $request->header('X-Booking-Strategy'));

        return (new ReservationResource($reservation))->response()->setStatusCode(201);
    }

    public function lookup(LookupReservationRequest $request): ReservationResource
    {
        return new ReservationResource($this->find($request));
    }

    public function cancel(LookupReservationRequest $request, ReservationStatusService $status): ReservationResource
    {
        $reservation = $status->transition($this->find($request), ReservationStatus::Cancelled);

        return new ReservationResource($reservation->load(['store', 'slot']));
    }

    /**
     * 代號與手機號碼都對才找得到。找不到與號碼不符回同樣的 404，不洩漏「這個代號存在」。
     */
    private function find(LookupReservationRequest $request): Reservation
    {
        return Reservation::query()
            ->with(['store', 'slot'])
            ->where('code', strtoupper($request->validated('code')))
            ->where('phone', $request->validated('phone'))
            ->first()
            ?? throw new ApiException('找不到此訂位，請確認代號與手機號碼', 'RESERVATION_NOT_FOUND', 404);
    }
}
