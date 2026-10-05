<?php

namespace App\Http\Controllers\Customer;

use App\Booking\ReservationFinder;
use App\Booking\ReservationService;
use App\Booking\ReservationStatusService;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateReservationRequest;
use App\Http\Requests\Customer\LookupReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Payments\PaymentService;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    public function store(CreateReservationRequest $request, ReservationService $service): JsonResponse
    {
        // 策略覆蓋標頭只給壓測用；是否生效由 CapacityStrategyResolver 決定
        $reservation = $service->create($request->validated(), $request->header('X-Booking-Strategy'));

        return (new ReservationResource($reservation))->response()->setStatusCode(201);
    }

    public function lookup(LookupReservationRequest $request, ReservationFinder $finder): ReservationResource
    {
        return new ReservationResource($finder->findForCustomer($request->validated('code'), $request->validated('phone')));
    }

    public function cancel(LookupReservationRequest $request, ReservationFinder $finder, ReservationStatusService $status): ReservationResource
    {
        $reservation = $finder->findForCustomer($request->validated('code'), $request->validated('phone'));

        return new ReservationResource($status->transition($reservation, ReservationStatus::Cancelled)->load(['store', 'slot', 'items']));
    }

    /** 建立付款，回傳要由瀏覽器 POST 給綠界的表單。 */
    public function pay(LookupReservationRequest $request, ReservationFinder $finder, PaymentService $payments): JsonResponse
    {
        $reservation = $finder->findForCustomer($request->validated('code'), $request->validated('phone'));

        return response()->json($payments->checkout($reservation));
    }
}
