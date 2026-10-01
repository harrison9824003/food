<?php

namespace App\Booking;

use App\Exceptions\ApiException;
use LogicException;

class CapacityStrategyResolver
{
    /**
     * @param  string|null  $requested  請求標頭 X-Booking-Strategy 的值（壓測用）
     *
     * @throws ApiException
     */
    public function resolve(?string $requested = null): CapacityStrategy
    {
        $name = config('booking.strategy');

        if ($requested !== null && $this->headerAllowed()) {
            $name = $requested;
        }

        // 無防護版本會造成超賣，任何情況都不可以在正式環境生效（設定檔指定也一樣）
        if ($name === 'unsafe' && app()->isProduction()) {
            throw new LogicException('unsafe 策略不可在正式環境使用');
        }

        return match ($name) {
            'atomic' => new AtomicUpdateStrategy,
            'pessimistic' => new PessimisticLockStrategy,
            'unsafe' => new UnsafeStrategy,
            default => throw new ApiException("不支援的訂位策略：{$name}", 'INVALID_STRATEGY', 422),
        };
    }

    /** 標頭覆蓋要「設定開啟」且「非正式環境」兩個條件同時成立，避免有人在線上用標頭切到無防護版本。 */
    private function headerAllowed(): bool
    {
        return config('booking.allow_strategy_header') && ! app()->isProduction();
    }
}
