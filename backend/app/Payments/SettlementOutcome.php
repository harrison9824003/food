<?php

namespace App\Payments;

/**
 * 處理一筆付款結果後，發生了什麼事。
 */
enum SettlementOutcome: string
{
    /** 付款成功，待付款的訂位已確認 */
    case Confirmed = 'confirmed';

    /** 訂位早已逾時釋放，但名額與桌位還在，成功補回並確認 */
    case Reinstated = 'reinstated';

    /** 這筆付款先前已處理過（綠界重送、對帳與通知同時到達…），這次沒有做任何事 */
    case AlreadyProcessed = 'already_processed';

    /** 錢已收到，但訂位無法成立（名額被搶走、重複付款、取消後才付）；已標記待退款 */
    case NeedsRefund = 'needs_refund';

    /** 付款失敗已記錄 */
    case Failed = 'failed';

    case NotFound = 'not_found';

    case AmountMismatch = 'amount_mismatch';

    /** 是否應該回覆金流商「1|OK」（讓它停止重送）。 */
    public function acknowledged(): bool
    {
        return match ($this) {
            self::Confirmed, self::Reinstated, self::AlreadyProcessed, self::NeedsRefund, self::Failed => true,
            self::NotFound, self::AmountMismatch => false,
        };
    }
}
