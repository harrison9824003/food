<?php

namespace App\Payments;

/**
 * 向金流商查詢一筆交易的結果。
 */
final readonly class TradeQueryResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $paid,
        public ?string $gatewayTradeNo,
        public ?int $amount,
        public ?string $paidAt,
        public array $raw = [],
    ) {}
}
