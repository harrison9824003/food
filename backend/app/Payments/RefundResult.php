<?php

namespace App\Payments;

/**
 * 金流商對「退款」這個要求的**明確答覆**。
 *
 * 只有拿到金流商的答覆才會有這個物件；「沒拿到答覆」（逾時、連線中斷、5xx）不是失敗，是「不知道」，
 * 由 PaymentGatewayException 表示——兩者必須分開，否則會把「其實已經退了」當成「沒退」而重複退款。
 */
final readonly class RefundResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}
}
