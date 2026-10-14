<?php

namespace App\Http\Controllers\Customer;

use App\Booking\ReservationFinder;
use App\Booking\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateReservationRequest;
use App\Http\Requests\Customer\LookupReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Payments\PaymentService;
use App\Refunds\CancellationService;
use App\Sms\PhoneAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReservationController extends Controller
{
    public function __construct(private readonly PhoneAuth $auth) {}

    public function store(CreateReservationRequest $request, ReservationService $service): JsonResponse
    {
        // 手機必須通過簡訊驗證，而且驗證的就是訂位填寫的這一支；憑證只能證明「持有這支手機」
        $this->auth->phone($request, $request->validated('phone'), mustMatch: true);

        // 策略覆蓋標頭只給壓測用；是否生效由 CapacityStrategyResolver 決定
        $reservation = $service->create(
            $request->validated() + ['phone_verified' => $this->auth->verified($request)],
            $request->header('X-Booking-Strategy'),
        );

        return (new ReservationResource($reservation))->response()->setStatusCode(201);
    }

    /** 這支手機的所有訂位（驗證過手機才看得到，不需要記得訂位代號）。 */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $claimed = $request->query('phone');
        $phone = $this->auth->phone($request, is_string($claimed) ? $claimed : null);

        $reservations = Reservation::query()
            ->with(['store', 'slot', 'items', 'refunds'])
            ->select('reservations.*')
            ->join('slots', 'slots.id', '=', 'reservations.slot_id')
            ->where('reservations.phone', $phone)
            ->orderByDesc('slots.slot_date')->orderByDesc('slots.start_time')
            ->limit(50)
            ->get();

        return ReservationResource::collection($reservations);
    }

    public function lookup(LookupReservationRequest $request, ReservationFinder $finder): ReservationResource
    {
        return new ReservationResource($finder->findForCustomer($request->validated('code'), $this->phone($request)));
    }

    public function cancel(LookupReservationRequest $request, ReservationFinder $finder, CancellationService $cancellation): ReservationResource
    {
        $reservation = $finder->findForCustomer($request->validated('code'), $this->phone($request));

        $cancelled = $cancellation->cancelByCustomer($reservation, (bool) $request->validated('acknowledge_forfeit', false));

        return new ReservationResource($cancelled->load(['store', 'slot', 'items', 'refunds']));
    }

    /** 建立付款，回傳要由瀏覽器 POST 給綠界的表單。 */
    public function pay(LookupReservationRequest $request, ReservationFinder $finder, PaymentService $payments): JsonResponse
    {
        $reservation = $finder->findForCustomer($request->validated('code'), $this->phone($request));

        return response()->json($payments->checkout($reservation));
    }

    private function phone(LookupReservationRequest $request): string
    {
        return $this->auth->phone($request, $request->validated('phone'));
    }
}
