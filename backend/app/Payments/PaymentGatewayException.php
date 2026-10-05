<?php

namespace App\Payments;

use RuntimeException;

/**
 * 無法從金流商取得可信的回應（連線失敗、逾時、回應簽章不符…）。
 * 呼叫端必須把它視為「不知道有沒有付款」，而不是「沒付款」。
 */
class PaymentGatewayException extends RuntimeException {}
