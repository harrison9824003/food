<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Arrived = 'arrived';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** 這些狀態仍佔用時段名額；其餘狀態應已歸還名額。 */
    public function holdsCapacity(): bool
    {
        return in_array($this, [self::PendingPayment, self::Confirmed, self::Arrived], true);
    }
}
