<?php

namespace App\Http\Controllers\Admin;

use App\Booking\ReservationStatusService;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReservationIndexRequest;
use App\Http\Requests\Admin\UpdateReservationStatusRequest;
use App\Http\Resources\AdminReservationResource;
use App\Models\Reservation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ReservationController extends Controller
{
    public function index(ReservationIndexRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Reservation::class);

        $actor = $request->user();
        $filters = $request->validated();

        $reservations = Reservation::query()
            ->with(['store', 'slot', 'tables'])
            ->select('reservations.*')
            ->join('slots', 'slots.id', '=', 'reservations.slot_id')
            // 總部可用 ?store_id= 篩選；其他角色固定只看自己門市，即使帶了別間的 store_id 也無效
            ->when(
                $actor->role === UserRole::HqAdmin,
                fn ($q) => $q->when($filters['store_id'] ?? null, fn ($q, $id) => $q->where('reservations.store_id', $id)),
                fn ($q) => $q->where('reservations.store_id', $actor->store_id),
            )
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->where('slots.slot_date', $date))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('reservations.status', $status))
            ->when($filters['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('reservations.code', 'like', $like)
                    ->orWhere('reservations.customer_name', 'like', $like)
                    ->orWhere('reservations.phone', 'like', $like));
            })
            ->orderByDesc('slots.slot_date')
            ->orderBy('slots.start_time')
            ->orderBy('reservations.id')
            ->paginate(20);

        return AdminReservationResource::collection($reservations);
    }

    public function updateStatus(UpdateReservationStatusRequest $request, Reservation $reservation, ReservationStatusService $status): AdminReservationResource
    {
        $updated = $status->transition($reservation, ReservationStatus::from($request->validated('status')));

        return new AdminReservationResource($updated->load(['store', 'slot', 'tables']));
    }
}
