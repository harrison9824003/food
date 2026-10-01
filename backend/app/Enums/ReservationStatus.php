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

    /** 這些狀態仍佔用時段名額；其餘狀態應已歸還名額與桌位。 */
    public function holdsCapacity(): bool
    {
        return in_array($this, [self::PendingPayment, self::Confirmed, self::Arrived], true);
    }

    /**
     * 狀態轉換表：只有這裡列出的轉換是合法的，其餘（例如已取消再標記已到）一律拒絕。
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Cancelled, self::Expired],
            self::Confirmed => [self::Arrived, self::NoShow, self::Cancelled],
            self::Arrived, self::NoShow, self::Cancelled, self::Expired => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => '待付款',
            self::Confirmed => '已確認',
            self::Arrived => '已到',
            self::NoShow => '未到',
            self::Cancelled => '已取消',
            self::Expired => '已逾時',
        };
    }
}
