<?php

namespace App\Booking;

use App\Exceptions\ApiException;
use App\Models\Reservation;

class ReservationFinder
{
    /**
     * 代號與手機號碼都對才找得到。找不到與號碼不符回同樣的 404，不洩漏「這個代號存在」。
     *
     * @throws ApiException
     */
    public function findForCustomer(string $code, string $phone): Reservation
    {
        return Reservation::query()
            ->with(['store', 'slot', 'items', 'refunds'])
            ->where('code', strtoupper($code))
            ->where('phone', $phone)
            ->first()
            ?? throw new ApiException('找不到此訂位，請確認代號與手機號碼', 'RESERVATION_NOT_FOUND', 404);
    }
}
