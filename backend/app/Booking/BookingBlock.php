<?php

namespace App\Booking;

/**
 * 某個時段「現在不能訂」的原因。
 */
final readonly class BookingBlock
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}

    public function httpStatus(): int
    {
        // 客滿是「狀態衝突」（稍後可能又有空位），其餘是請求本身不符合規則
        return in_array($this->code, ['SLOT_FULL', 'NO_TABLE_AVAILABLE'], true) ? 409 : 422;
    }
}
